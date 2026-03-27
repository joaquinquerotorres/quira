<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class GeminiService
{
    public function __construct(
        #[Autowire('%env(GEMINI_API_KEY)%')] 
        private string $apiKey,
        private readonly HttpClientInterface $client,
        private readonly LoggerInterface $logger
    ) {
        $this->apiKey = $apiKey;
    }

    public function diagnose(?string $description, ?string $image, ?string $audio = null, ?string $video = null, ?string $location = null, ?string $cacheId = null): array
    {
        // Para ahora: si no hay ubicación explícita, asumimos siempre Córdoba (Andalucía, España).
        // En el futuro, el frontend podrá enviar otras ciudades/regiones de España en $location.
        $locationContext = $location ? trim($location) : 'Córdoba, Andalucía, España';

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . $this->apiKey;
        
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
                'inline_data' => [
                    'mime_type' => 'video/mp4',
                    'data' => preg_replace('#^data:video/[^;]+;base64,#', '', $video)
                ]
            ];
        }

        if (!empty($image)) {
            $parts[] = [
                'inline_data' => [
                    'mime_type' => 'image/jpeg',
                    'data' => preg_replace('#^data:image/[^;]+;base64,#', '', $image)
                ]
            ];
        }

        if (!empty($audio)) {
            $parts[] = [
                'inline_data' => [
                    'mime_type' => 'audio/mp3', 
                    'data' => preg_replace('#^data:audio/[^;]+;base64,#', '', $audio)
                ]
            ];
        }

        $payload = [
            'contents' => [
                [
                    'parts' => $parts
                ]
            ],
            'cachedContent' => $cacheId,
            'generationConfig' => [
                'response_mime_type' => 'application/json',
                'temperature' => 0.2,
            ]
        ];

        try {
            $response = $this->client->request('POST', $url, [
                'json' => $payload,
                'headers' => ['Content-Type' => 'application/json']
            ]);

            $data = $response->toArray();
            
            $rawJson = $data['candidates'][0]['content']['parts'][0]['text'] ?? '{}';

            $cleanJson = str_replace(['```json', '```'], '', $rawJson);
            
            $this->logger->info("✅ Predicción generada exitosamente por Gemini para la solicitud. Respuesta cruda: " . $rawJson);
            
            return json_decode($cleanJson, true);
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
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . $this->apiKey;
        
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
            $parts[] = ['inline_data' => ['mime_type' => 'video/mp4', 'data' => preg_replace('#^data:video/[^;]+;base64,#', '', $video)]];
        }
        if (!empty($image)) {
            $parts[] = ['inline_data' => ['mime_type' => 'image/jpeg', 'data' => preg_replace('#^data:image/[^;]+;base64,#', '', $image)]];
        }
        if (!empty($audio)) {
            $parts[] = ['inline_data' => ['mime_type' => 'audio/mp3', 'data' => preg_replace('#^data:audio/[^;]+;base64,#', '', $audio)]];
        }

        $payload = [
            'contents' => [['parts' => $parts]],
            'generationConfig' => [
                'response_mime_type' => 'application/json',
                'temperature' => 0.0, 
            ]
        ];

        try {
            $response = $this->client->request('POST', $url, [
                'json' => $payload,
                'headers' => ['Content-Type' => 'application/json']
            ]);

            $data = $response->toArray();
            $rawJson = $data['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
            $cleanJson = str_replace(['```json', '```'], '', $rawJson);
            
            $this->logger->info("✅ Predicción generada exitosamente por Gemini para la solicitud. Respuesta cruda: " . $rawJson);
            return json_decode($cleanJson, true);

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
                "model" => "models/gemini-2.5-flash",
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
}