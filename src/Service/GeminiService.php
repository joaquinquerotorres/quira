<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GeminiService
{
    public function __construct(
        #[Autowire('%env(GEMINI_API_KEY)%')] 
        private string $apiKey,
        #[Autowire('%env(default:models/gemini-2.5-flash:GEMINI_MODEL)%')]
        private string $model,
        private readonly HttpClientInterface $client,
        private readonly LoggerInterface $logger
    ) {
        $this->apiKey = $apiKey;
        $this->model = $this->normalizeModelName($this->model);
    }

    public function diagnose(?string $description, ?string $image, ?string $audio = null, ?string $video = null, ?string $location = null, ?string $cacheId = null): array
    {
        // Para ahora: si no hay ubicación explícita, asumimos siempre Córdoba (Andalucía, España).
        // En el futuro, el frontend podrá enviar otras ciudades/regiones de España en $location.
        $locationContext = $location ? trim($location) : 'Córdoba, Andalucía, España';

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $this->model . ':generateContent?key=' . $this->apiKey;
        
        $instruction = match (true) {
            !empty($video) && !empty($description) => 
                "Analiza el VIDEO proporcionado como fuente principal de verdad (contiene imagen y explicación de voz). Usa el texto '$description' solo para aclarar detalles puntuales.",

            !empty($video) => 
                "Analiza EXCLUSIVAMENTE el VIDEO proporcionado. Observa los fotogramas para identificar el daño y ESCUCHA el audio del video para entender la explicación del usuario. El video es autodescriptivo.",

            !empty($audio) && !empty($image) && !empty($description) => 
                "Analiza con PRIORIDAD el AUDIO y la IMAGEN. Usa el texto '$description' como apoyo secundario.",

            !empty($audio) && !empty($image) => 
                "Combina la explicación del AUDIO con la evidencia visual de la IMAGEN para diagnosticar el problema.",

            !empty($audio) && !empty($description) => 
                "Analiza el AUDIO proporcionado. Si hay ambigüedad, consulta el texto '$description'.",

            !empty($audio) => 
                "Analiza EXCLUSIVAMENTE el contenido del AUDIO. El usuario describe su problema de viva voz. Ignora muletillas y extrae la necesidad técnica.",

            !empty($description) && !empty($image) => 
                "Analiza la IMAGEN proporcionada y correlaciónala con esta DESCRIPCIÓN: '$description'.",

            !empty($image) => 
                "Analiza la IMAGEN proporcionada. Deduce el servicio necesario basándote puramente en la evidencia visual (roturas, manchas, cables sueltos, etc.).",

            default => 
                "Basándote EXCLUSIVAMENTE en la siguiente DESCRIPCIÓN textual: '$description', determina el servicio necesario."
        };

        $systemPrompt = <<<PROMPT
            $instruction

            CONTEXTO GEOGRÁFICO (SOLO ESPAÑA):
            - Ubicación de la solicitud actual: "$locationContext".
            - Si no se te indica explícitamente la ubicación, ASUME SIEMPRE: "Córdoba, Andalucía, España".
            
            INSTRUCCIÓN DE COTIZACIÓN GEOGRÁFICA (ESPAÑA):
            - Ajusta los precios (min/max) basándote en el coste de vida de la ciudad/región.
            - Considera estas reglas:
              * "Madrid", "Barcelona", "Bilbao" => mano de obra MUY ALTA.
              * "Valencia", "Sevilla", "Málaga", "Zaragoza", "Palma de Mallorca" => mano de obra ALTA.
              * "Córdoba", "Granada", "Cádiz", "Almería", "Jaén", "Huelva" => mano de obra MEDIA (usa especialmente filas de zona ANDALUCÍA si existen).
              * Ciudades pequeñas/pueblos => mano de obra MEDIA/BAJA (usa filas "Nacional" sin sobrecostes).
            - Si la ciudad está en Andalucía (ej: Córdoba, Sevilla, Málaga, Granada...), PRIORIZA filas con Zona = "Andalucía". 
              Solo usa filas de Zona "Nacional" cuando no exista una fila específica para Andalucía.

            Actúa como un experto cotizador de servicios del hogar, mantenimiento y reformas.
            NO asumas siempre que es una avería.
            Puede ser:
            1. Una REPARACIÓN (algo roto, fuga, chispazo).
            2. Una INSTALACIÓN (montar muebles, colgar lámparas, instalar grifo).
            3. Una MEJORA (pintar una pared, jardinería, limpieza a fondo).

            CATEGORÍAS DISPONIBLES (Elige estrictamente una):
            - MASONRY (Albañilería/Reformas)
            - PLUMBING (Fontanería)
            - ELECTRICITY (Electricidad)
            - HVAC (Climatización/Aire Acondicionado)
            - DIY (Manitas/Montaje muebles/Bricolaje)
            - PAINTING (Pintura)
            - GARDENING (Jardinería)
            - CLEANING (Limpieza)

            REGLAS DE PRECIOS (MUY ESTRICTAS):
            - Usa SIEMPRE la tabla de precios reales y las reglas de analogía que tienes en tu CONTEXTO CACHEADO.
            - El rango final [estimated_price_min, estimated_price_max] debe estar DENTRO o MUY CERCA del rango [Precio_Min, Precio_Max] de la fila de la tabla que elijas:
              * En trabajos normales, mantente dentro del rango de la tabla.
              * En urgencias ("IMMEDIATE"), puedes incrementar el rango hasta un máximo del 30% respecto al Precio_Max original, pero nunca superar 1.3 * Precio_Max.
            - NO inventes rangos totalmente nuevos si existen filas similares en la tabla.
            - Devuelve SIEMPRE los precios en CÉNTIMOS (enteros).

            Devuelve UNICAMENTE un objeto JSON con esta estructura:
            {
                'title': 'Título corto y profesional (ej: Pintura de salón 20m2, Reparación de fuga)',
                'description': 'Descripción técnica del trabajo y herramientas/materiales probables.',
                'summary_text': 'Resumen en 1 frase de lo que has entendido del audio/texto del usuario (ej: "Entendido, tienes una fuga de agua importante en el lavabo")',
                'category': 'La categoría técnica en MAYÚSCULAS (ej: PLUMBING)',
                'sub_category': 'Subcategoría textual lo más cercana posible a una de las filas de la columna "Subcategoria" del CSV de precios (ej: "Instalación de termo eléctrico 80L", "Cambio de mecanismos (enchufe/tecla)"). Si no encuentras una coincidencia clara, devuelve una descripción corta estándar que luego pueda mapearse manualmente.',
                'risk_level': 'LOW (Estético/Jardín/Limpieza), MEDIUM (Reparaciones estándar), HIGH (Gas/Electricidad/Estructural)',
                'estimated_price_min': (entero en céntimos, ej: 3000 para 30€),
                'estimated_price_max': (entero en céntimos),
                'urgency': 'IMMEDIATE' (si implica seguridad/daños) o 'SCHEDULED',
                'schedule_intent': 'Texto detectado sobre la fecha (ej: "Mañana por la tarde") o null'
            }

            REGLAS DE SEGURIDAD Y "HUMILDAD":
            1. Si el audio o video es ininteligible o hay silencio, marca la categoría como 'DIY' y en el título pon: "Solicitud general (Audio no claro)".
            2. Si detectas palabras clave contradictorias (ej: "fuga de agua" pero se ve un enchufe), prioriza lo VISUAL (Video/Imagen) sobre el audio.
            3. NO inventes herramientas. Si no estás seguro, pon "Herramientas estándar de diagnóstico".
            4. Si dudas entre dos categorías (ej: Albañilería vs Fontanería para cambiar un plato de ducha), elige la más general o compleja (MASONRY).
            
            REGLAS DE DECISIÓN:
            - Brochas, rodillos, paredes descoloridas -> PAINTING
            - Plantas, podas, tierra, exteriores -> GARDENING
            - Suciedad general, cristales, fin de obra -> CLEANING
            - Cajas/instrucciones -> DIY
            - Cables/enchufes, chispas -> ELECTRICITY
            - Agua/grifos -> PLUMBING
            - Escombros/ladrillos -> MASONRY
            - Aire acondicionado/radiadores -> HVAC
            
            Ante la duda técnica, usa DIY.
            
        PROMPT;

        $parts = [
            ['text' => $systemPrompt]
        ];

        if (!empty($video)) {
            $parts[] = [
                'inline_data' => $this->toInlineData($video, 'video/mp4')
            ];
        }

        if (!empty($image)) {
            $parts[] = [
                'inline_data' => $this->toInlineData($image, 'image/jpeg')
            ];
        }

        if (!empty($audio)) {
            $parts[] = [
                'inline_data' => $this->toInlineData($audio, 'audio/mp3', true),
            ];
        }

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => $parts,
                ],
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'temperature' => 0.2,
            ],
        ];
        if (!empty($cacheId)) {
            $payload['cachedContent'] = $cacheId;
        }

        try {
            $rawJson = $this->requestGenerateContent($url, $payload);
            $cleanJson = str_replace(['```json', '```'], '', $rawJson);
            $decoded = json_decode($cleanJson, true);
            if (!is_array($decoded)) {
                throw new \RuntimeException('Gemini devolvió contenido no JSON en diagnose()');
            }

            $this->logger->info("✅ Predicción generada exitosamente por Gemini para la solicitud. Respuesta cruda: " . $rawJson);
            return $decoded;
        } catch (\Exception $e) {
            $this->logger->error("❌ Error al conectar con el servicio de IA: " . $e->getMessage());
            return [
                'title' => 'Solicitud pendiente de revisión',
                'description' => $description ?? 'El contenido multimedia no pudo ser procesado automáticamente.',
                'summary_text' => 'Error en análisis automático.',
                'category' => 'DIY',
                'risk_level' => 'LOW',
                'estimated_price_min' => 0,
                'estimated_price_max' => 0,
                'urgency' => 'SCHEDULED',
                'schedule_intent' => null
            ];
        }
    }

    public function checkSafety(?string $title, ?string $description, ?string $image = null, ?string $audio = null, ?string $video = null): array
    {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $this->model . ':generateContent?key=' . $this->apiKey;
        
        $systemPrompt = <<<PROMPT
            Actúa como el sistema de seguridad 'Sentinel' de la App Quira.
            Tu ÚNICA misión es detectar contenido prohibido en esta solicitud de trabajo.

            DATOS A ANALIZAR:
            - Título: "$title"
            - Descripción: "$description"
            - Archivos adjuntos: (Audio/Video/Imagen si los hay)

            BUSCA ACTIVAMENTE ESTAS 2 INFRACCIONES:
            1. FRAUDE DE CONTACTO (CRÍTICO):
               - El usuario intenta compartir su TELÉFONO ("seis cero nueve...", "llámame al..."), EMAIL o REDES SOCIALES.
               - Revisa si lo dice en el AUDIO, si lo escribe en un papel en la IMAGEN/VIDEO, o si está en el TEXTO.
            2. CONTENIDO OFENSIVO:
               - Insultos graves, amenazas, contenido sexual o violencia.

            RESPONDE SOLO CON ESTE JSON:
            {
                "is_safe": true o false,
                "reason": "Si is_safe es false, explica brevemente por qué (ej: 'Usuario dicta teléfono en audio'). Si es true, null."
            }
        PROMPT;

        $parts = [['text' => $systemPrompt]];

        if (!empty($video)) {
            $parts[] = ['inline_data' => $this->toInlineData($video, 'video/mp4')];
        }
        if (!empty($image)) {
            $parts[] = ['inline_data' => $this->toInlineData($image, 'image/jpeg')];
        }
        if (!empty($audio)) {
            $parts[] = ['inline_data' => $this->toInlineData($audio, 'audio/mp3', true)];
        }

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => $parts,
                ],
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'temperature' => 0.0,
            ],
        ];

        try {
            $rawJson = $this->requestGenerateContent($url, $payload);
            $cleanJson = str_replace(['```json', '```'], '', $rawJson);
            $decoded = json_decode($cleanJson, true);
            if (!is_array($decoded) || !array_key_exists('is_safe', $decoded)) {
                throw new \RuntimeException('Gemini devolvió contenido no JSON o incompleto en checkSafety()');
            }

            $this->logger->info("✅ Predicción generada exitosamente por Gemini para verificación de seguridad. Respuesta cruda: " . $rawJson);
            return $decoded;

        } catch (\Exception $e) {
            $this->logger->error("❌ Error al conectar con el servicio de IA para verificación de seguridad: " . $e->getMessage());
            return [
                'is_safe' => false,
                'reason' => 'Error de verificación automática. Requiere revisión manual.',
            ];
        }
    }

    public function createCache(): ?string
    {
        $url = 'https://generativelanguage.googleapis.com/v1beta/cachedContents?key=' . $this->apiKey;
        $csvPath = \dirname(__DIR__, 2) . '/config/gemini_pricing.csv';
        $csvContents = @file_get_contents($csvPath);
        if ($csvContents === false) {
            $this->logger->error("❌ No se pudo leer config/gemini_pricing.csv. Usando tabla de precios vacía en el contexto de Gemini.");
            $csvContents = "Categoria,Subcategoria,Zona,Precio_Min,Precio_Max,Unidad,Complejidad\n";
        }

        $staticContext = <<<CONTEXT
            IDENTIDAD Y ROL:
            Eres Quira, la Inteligencia Artificial avanzada de la plataforma Quira. Tu especialidad es el mantenimiento integral, reparaciones técnicas y reformas del hogar en España. Actúas como un gestor técnico y cotizador experto. Tu tono es profesional, analítico y resolutivo.

            CONTEXTO DE OPERACIÓN:
            Operas en el contexto de Quira, priorizando la precisión técnica y la seguridad del usuario. Utilizas la evidencia multimodal (video, audio, imagen) para generar diagnósticos que ayuden a los profesionales a entender el trabajo antes de asistir.

            DESCRIPCIÓN DETALLADA DE CATEGORÍAS TÉCNICAS:
            - PLUMBING (Fontanería): Incluye redes de agua sanitaria, evacuación, grifería, sanitarios, sistemas de filtración (ósmosis/descalcificación) y detección de fugas.
            - ELECTRICITY (Electricidad): Instalaciones de baja tensión, cuadros eléctricos, mecanismos, iluminación LED, antenas, videoporteros y certificados de eficiencia.
            - MASONRY (Albañilería): Reformas estructurales, tabiquería, alicatados, solados, enfoscados de cemento, yeso y reparaciones de humedades.
            - HVAC (Climatización): Aire acondicionado por conductos o split, calderas de gas/gasoil, termos eléctricos, aerotermia y suelo radiante.
            - DIY (Manitas/Bricolaje): Montaje de mobiliario, instalación de accesorios (cortinas, cuadros), ajuste de carpintería metálica o madera, y reparaciones menores.
            - PAINTING (Pintura): Pintura plástica, decorativa, eliminación de gotelé, lacado de puertas, tratamiento de maderas y papeles pintados.
            - GARDENING (Jardinería): Diseño de paisajes, mantenimiento de césped, podas en altura, sistemas de riego automatizado y tratamientos fitosanitarios.
            - CLEANING (Limpieza): Limpiezas de choque post-obra, higienización de tapicerías, limpieza de cristales en altura y mantenimiento regular.

            TABLA MAESTRA DE PRECIOS QUIRA (desde CSV externo):
            $csvContents

            LOGICA DE FALLBACK Y ANALOGÍA:
            - Si el servicio no es exacto: identifica la categoría y aplica el rango de un servicio de similar dificultad dentro de la MISMA categoría y, si es posible, de la misma zona (Andalucía/Nacional).
            - Solo si no hay ninguna analogía razonable en la tabla:
              * Usa precios por hora: Técnicos (PLUMBING, ELECTRICITY, HVAC, MASONRY) 4800-7200 cént/h. 
                Soporte (PAINTING, GARDENING, CLEANING, DIY) 1800-3800 cént/h.
              * Limita el número de horas a un rango realista para trabajos domésticos estándar (por ejemplo 1-2h, 2-4h, 4-6h) y ajusta el rango de precio en consecuencia.
            - Urgencias: Si el usuario requiere asistencia inmediata y el servicio tiene carácter urgente (fuga grave, riesgo eléctrico, etc.), incrementa los rangos hasta un 30% máximo respecto al rango base y márcalo como 'IMMEDIATE'.

            REGLAS DE SEGURIDAD Y PRECISIÓN:
            1. Prioridad: Video > Imagen > Audio > Texto.
            2. Si detectas cables pelados o chispas: RIESGO ALTO.
            3. Si detectas fugas de agua cerca de electricidad: RIESGO ALTO.
            4. Siempre devuelve el JSON completo con precios en céntimos (enteros).
        CONTEXT;
        
         try {
            $payload = [
                "model" => "models/" . $this->model,
                "displayName" => "Quira Knowledge Base",
                "contents" => [
                    ["parts" => [["text" => $staticContext]], "role" => "user"]
                ],
                "ttl" => "3600s" 
            ];

            $response = $this->client->request('POST', $url, ['json' => $payload]);
            $data = $response->toArray();
            
            return $data['name']; 

        } catch (\Exception $e) {
            $this->logger->error("❌ Error al crear la cache en Gemini: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Convierte un Data URL (data:<mime>;base64,<data>) o base64 "crudo" en inline_data para Gemini.
     * Mantiene el mime_type real cuando viene en el Data URL para evitar rechazos por mismatch.
     *
     * Para audio ($isAudio): detecta el formato por cabecera (WebM, MP3, WAV, M4A…). Si el cliente
     * envía base64 sin prefijo o declara audio/mpeg pero el binario es WebM, la API devolvía 400.
     *
     * @return array{mime_type: string, data: string}
     */
    private function toInlineData(string $dataUrlOrBase64, string $fallbackMimeType, bool $isAudio = false): array
    {
        $mimeType = $fallbackMimeType;
        $data = $dataUrlOrBase64;

        if (preg_match('#^data:([^;]+);base64,#', $dataUrlOrBase64, $m) === 1) {
            $mimeType = trim($m[1]);
            $data = preg_replace('#^data:[^;]+;base64,#', '', $dataUrlOrBase64);
        }

        $data = $this->normalizeBase64Payload($data);

        if ($isAudio) {
            $mimeType = $this->resolveAudioMimeType($mimeType, $data);
        } else {
            if ($mimeType === 'audio/mp3') {
                $mimeType = 'audio/mpeg';
            }
            if ($mimeType === 'audio/m4a') {
                $mimeType = 'audio/mp4';
            }
        }

        return [
            'mime_type' => $mimeType,
            'data' => $data,
        ];
    }

    private function normalizeBase64Payload(string $base64): string
    {
        $base64 = preg_replace('/\s+/', '', $base64) ?? '';
        $pad = strlen($base64) % 4;
        if ($pad > 0) {
            $base64 .= str_repeat('=', 4 - $pad);
        }

        return $base64;
    }

    /**
     * Ajusta MIME de audio al binario y a los nombres que documenta Gemini (p. ej. audio/mp3).
     *
     * @see https://ai.google.dev/gemini-api/docs/audio
     */
    private function resolveAudioMimeType(string $declaredMime, string $base64Payload): string
    {
        $binary = base64_decode($base64Payload, true);
        if ($binary === false || strlen($binary) < 4) {
            return $this->normalizeAudioMimeForGemini($declaredMime);
        }

        $sniffed = $this->sniffAudioMimeFromBinary($binary);
        if ($sniffed !== null && $this->shouldPreferSniffedAudioMime($declaredMime, $sniffed)) {
            return $this->normalizeAudioMimeForGemini($sniffed);
        }

        return $this->normalizeAudioMimeForGemini($declaredMime);
    }

    private function shouldPreferSniffedAudioMime(string $declaredMime, string $sniffed): bool
    {
        $d = strtolower(trim($declaredMime));
        if ($d === '' || $d === 'application/octet-stream') {
            return true;
        }
        if (in_array($d, ['audio/mpeg', 'audio/mp3', 'audio/mp4', 'audio/m4a', 'audio/x-m4a', 'audio/x-mpeg'], true)) {
            return true;
        }
        // Declarado como MPEG pero el binario es WebM (p. ej. cliente envía tipo genérico incorrecto).
        if (str_contains($d, 'mpeg') && $sniffed === 'audio/webm') {
            return true;
        }

        return false;
    }

    private function normalizeAudioMimeForGemini(string $mimeType): string
    {
        return match (strtolower(trim($mimeType))) {
            'audio/mpeg', 'audio/x-mpeg' => 'audio/mp3',
            'audio/mp3' => 'audio/mp3',
            'audio/m4a', 'audio/x-m4a' => 'audio/mp4',
            default => $mimeType,
        };
    }

    private function sniffAudioMimeFromBinary(string $b): ?string
    {
        $len = strlen($b);
        if ($len < 4) {
            return null;
        }

        // WebM / Matroska (EBML)
        if ($b[0] === "\x1a" && $b[1] === 'E' && $b[2] === "\xdf" && $b[3] === "\xa3") {
            return 'audio/webm';
        }

        if ($len >= 12 && str_starts_with($b, 'RIFF') && substr($b, 8, 4) === 'WAVE') {
            return 'audio/wav';
        }

        if ($len >= 12 && str_starts_with($b, 'FORM') && substr($b, 8, 4) === 'AIFF') {
            return 'audio/aiff';
        }

        if (str_starts_with($b, 'OggS')) {
            return 'audio/ogg';
        }

        if (str_starts_with($b, 'fLaC')) {
            return 'audio/flac';
        }

        if (str_starts_with($b, 'ID3')) {
            return 'audio/mp3';
        }

        $b0 = \ord($b[0]);
        $b1 = \ord($b[1]);
        if (($b0 & 0xFF) === 0xFF && (($b1 & 0xE0) === 0xE0)) {
            return 'audio/mp3';
        }

        if ($len >= 2 && ($b0 & 0xFF) === 0xFF && (($b1 & 0xF6) === 0xF0)) {
            return 'audio/aac';
        }

        if ($len >= 12 && substr($b, 4, 4) === 'ftyp') {
            return 'audio/mp4';
        }

        return null;
    }

    private function requestGenerateContent(string $url, array $payload): string
    {
        try {
            $response = $this->client->request('POST', $url, [
                'json' => $payload,
                'headers' => ['Content-Type' => 'application/json'],
            ]);

            $data = $response->toArray();
            return (string) ($data['candidates'][0]['content']['parts'][0]['text'] ?? '{}');
        } catch (\Throwable $e) {
            if ($e instanceof HttpExceptionInterface) {
                try {
                    $this->logger->warning('Gemini API error body: ' . $e->getResponse()->getContent(false));
                } catch (\Throwable) {
                }
            }
            $fallbackModel = 'gemini-2.0-flash';
            if ($this->model !== $fallbackModel) {
                $fallbackUrl = preg_replace('#/models/[^:]+:generateContent\\?key=#', '/models/' . $fallbackModel . ':generateContent?key=', $url);
                if (is_string($fallbackUrl)) {
                    $this->logger->warning("⚠️ Fallback a modelo {$fallbackModel} por error con {$this->model}: " . $e->getMessage());
                    $response = $this->client->request('POST', $fallbackUrl, [
                        'json' => $payload,
                        'headers' => ['Content-Type' => 'application/json'],
                    ]);
                    $data = $response->toArray();
                    return (string) ($data['candidates'][0]['content']['parts'][0]['text'] ?? '{}');
                }
            }
            throw $e;
        }
    }

    private function normalizeModelName(string $model): string
    {
        $model = trim($model);
        if ($model === '') {
            return 'gemini-2.5-flash';
        }
        if (str_starts_with($model, 'models/')) {
            return substr($model, strlen('models/'));
        }
        return $model;
    }
}