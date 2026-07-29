<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recarga catálogo pricing_rate desde gemini_pricing_master_updated (151 filas)
 * e invalida cachés Gemini locales.
 */
final class Version20260729200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace pricing_rate with expanded catalog (22 categories, 151 rates)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DELETE FROM pricing_rate');
        $this->addSql('UPDATE gemini_cache SET expires_at = DATE_SUB(NOW(), INTERVAL 1 DAY)');

        $this->addSql(<<<'SQL'
INSERT INTO pricing_rate (category_code, category_label, subcategory, zone, price_min, price_max, unit, complexity) VALUES
('PLUMBING', 'Fontanería', 'Sustitución de grifo monomando (Lavabo/Fregadero)', 'Córdoba', 3500, 7500, 'Unidad', 'Baja'),
('PLUMBING', 'Fontanería', 'Instalación de columna de ducha/Conjunto', 'Córdoba', 5500, 11000, 'Unidad', 'Media'),
('PLUMBING', 'Fontanería', 'Reparación fuga en tubería de cobre (soldadura)', 'Córdoba', 6500, 14000, 'Servicio', 'Media'),
('PLUMBING', 'Fontanería', 'Reparación fuga en tubería de multicapa/PVC', 'Córdoba', 5000, 11000, 'Servicio', 'Media'),
('PLUMBING', 'Fontanería', 'Desatasco manual de sifón/desagüe', 'Córdoba', 4000, 8000, 'Servicio', 'Baja'),
('PLUMBING', 'Fontanería', 'Desatasco mecánico con máquina de presión', 'Andalucía', 9000, 18000, 'Servicio', 'Media'),
('PLUMBING', 'Fontanería', 'Localización de fuga con cámara térmica/geófono', 'Andalucía', 12000, 35000, 'Servicio', 'Alta'),
('PLUMBING', 'Fontanería', 'Sustitución de mecanismo de descarga cisterna', 'Córdoba', 4500, 9000, 'Servicio', 'Baja'),
('PLUMBING', 'Fontanería', 'Instalación de termo eléctrico hasta 100L', 'Andalucía', 12000, 22000, 'Instalación', 'Media'),
('PLUMBING', 'Fontanería', 'Sustitución de llave de escuadra o paso', 'Córdoba', 2500, 5500, 'Unidad', 'Baja'),
('PLUMBING', 'Fontanería', 'Instalación de equipo de ósmosis (mano obra)', 'Córdoba', 6500, 15000, 'Servicio', 'Media'),
('PLUMBING', 'Fontanería', 'Instalación de descalcificador doméstico', 'Andalucía', 15000, 35000, 'Instalación', 'Alta'),
('PLUMBING', 'Fontanería', 'Sustitución de bajante comunitaria (tramo metro)', 'Andalucía', 15000, 45000, 'Metro', 'Alta'),
('ELECTRICITY', 'Electricidad', 'Instalación de punto de luz/Lámpara', 'Córdoba', 2500, 5000, 'Unidad', 'Baja'),
('ELECTRICITY', 'Electricidad', 'Sustitución de enchufe o interruptor', 'Córdoba', 1200, 3000, 'Unidad', 'Baja'),
('ELECTRICITY', 'Electricidad', 'Instalación de ventilador de techo', 'Córdoba', 4500, 9500, 'Unidad', 'Media'),
('ELECTRICITY', 'Electricidad', 'Sustitución de diferencial o térmico defectuoso', 'Córdoba', 3500, 7500, 'Unidad', 'Media'),
('ELECTRICITY', 'Electricidad', 'Reforma de cuadro eléctrico (vivienda estándar)', 'Andalucía', 25000, 55000, 'Instalación', 'Alta'),
('ELECTRICITY', 'Electricidad', 'Boletín Eléctrico / Certificado CIE', 'Andalucía', 9000, 18000, 'Certificado', 'Media'),
('ELECTRICITY', 'Electricidad', 'Instalación de videoportero (mano obra)', 'Córdoba', 12000, 28000, 'Instalación', 'Alta')
SQL);

        $this->addSql(<<<'SQL'
INSERT INTO pricing_rate (category_code, category_label, subcategory, zone, price_min, price_max, unit, complexity) VALUES
('ELECTRICITY', 'Electricidad', 'Punto de recarga para coche eléctrico (Wallbox)', 'España', 45000, 110000, 'Instalación', 'Alta'),
('ELECTRICITY', 'Electricidad', 'Instalación de antena TV o toma adicional', 'Córdoba', 5500, 12000, 'Servicio', 'Media'),
('ELECTRICITY', 'Electricidad', 'Detección y reparación de derivación/fuga', 'Córdoba', 6000, 18000, 'Servicio', 'Alta'),
('ELECTRICITY', 'Electricidad', 'Instalación de domótica básica (Shelly/Sonoff)', 'Córdoba', 3500, 8500, 'Dispositivo', 'Media'),
('MASONRY', 'Albañilería', 'Alicatado de paramentos (mano obra)', 'Córdoba', 2000, 4500, 'm2', 'Media'),
('MASONRY', 'Albañilería', 'Solado con baldosa cerámica/gres', 'Córdoba', 1800, 4000, 'm2', 'Media'),
('MASONRY', 'Albañilería', 'Tabiquería de ladrillo hueco (sin enlucir)', 'Córdoba', 2500, 5000, 'm2', 'Media'),
('MASONRY', 'Albañilería', 'Falso techo de Pladur (mano obra)', 'Córdoba', 1800, 3500, 'm2', 'Media'),
('MASONRY', 'Albañilería', 'Enlucido de paredes con yeso/perlita', 'Córdoba', 900, 1800, 'm2', 'Baja'),
('MASONRY', 'Albañilería', 'Desescombro y transporte a punto limpio', 'Córdoba', 1500, 3500, 'm2', 'Baja'),
('MASONRY', 'Albañilería', 'Cambio de bañera por plato de ducha (obra)', 'Andalucía', 35000, 75000, 'Servicio', 'Alta'),
('MASONRY', 'Albañilería', 'Impermeabilización de azotea/terraza', 'Andalucía', 3800, 9000, 'm2', 'Alta'),
('MASONRY', 'Albañilería', 'Reparación de humedades/filtraciones', 'Córdoba', 5000, 15000, 'Servicio', 'Alta'),
('MASONRY', 'Albañilería', 'Apertura y cierre de rozas (fontanería/elect)', 'Córdoba', 1200, 2800, 'Metro', 'Media'),
('HVAC', 'Climatización', 'Instalación Split 1x1 hasta 3000 frig', 'Andalucía', 14000, 22000, 'Instalación', 'Media'),
('HVAC', 'Climatización', 'Instalación aire por conductos (mano obra)', 'Andalucía', 45000, 95000, 'Instalación', 'Alta'),
('HVAC', 'Climatización', 'Carga de gas refrigerante R32/R410A', 'Córdoba', 8500, 18000, 'Servicio', 'Media'),
('HVAC', 'Climatización', 'Mantenimiento preventivo (limpieza/filtros)', 'Córdoba', 5500, 9500, 'Servicio', 'Baja'),
('HVAC', 'Climatización', 'Instalación de caldera de gas condensación', 'España', 35000, 70000, 'Instalación', 'Alta'),
('HVAC', 'Climatización', 'Instalación de aerotermia (mano obra básica)', 'España', 150000, 450000, 'Instalación', 'Alta')
SQL);

        $this->addSql(<<<'SQL'
INSERT INTO pricing_rate (category_code, category_label, subcategory, zone, price_min, price_max, unit, complexity) VALUES
('HVAC', 'Climatización', 'Limpieza y purgado de radiadores (vivienda)', 'Córdoba', 4500, 9000, 'Servicio', 'Media'),
('HVAC', 'Climatización', 'Instalación de estufa de pellets (salida humo)', 'Andalucía', 25000, 55000, 'Instalación', 'Alta'),
('PAINTING', 'Pintura', 'Pintura plástica blanca lisa (2 manos)', 'Córdoba', 650, 1100, 'm2', 'Baja'),
('PAINTING', 'Pintura', 'Pintura plástica color (2 manos)', 'Córdoba', 1000, 1800, 'm2', 'Baja'),
('PAINTING', 'Pintura', 'Quitar gotelé y alisado (mano de obra)', 'Córdoba', 1400, 3200, 'm2', 'Alta'),
('PAINTING', 'Pintura', 'Lacado de puertas en blanco (a pistola/unidad)', 'Córdoba', 7500, 14000, 'Unidad', 'Alta'),
('PAINTING', 'Pintura', 'Tratamiento de madera exterior/Lasur', 'Andalucía', 1500, 3500, 'm2', 'Media'),
('PAINTING', 'Pintura', 'Pintura de fachada (pintura al silicato)', 'Andalucía', 1300, 3800, 'm2', 'Media'),
('PAINTING', 'Pintura', 'Pintura de suelo epoxi (garajes)', 'España', 1800, 4500, 'm2', 'Alta'),
('PAINTING', 'Pintura', 'Colocación de papel pintado (mano obra)', 'Córdoba', 1200, 2800, 'm2', 'Media'),
('GARDENING', 'Jardinería', 'Mantenimiento básico (siega y soplado)', 'Córdoba', 3500, 8000, 'Servicio', 'Baja'),
('GARDENING', 'Jardinería', 'Diseño e instalación riego automático', 'Andalucía', 8000, 25000, 'Servicio', 'Media'),
('GARDENING', 'Jardinería', 'Poda de palmeras o árboles en altura', 'Andalucía', 7500, 35000, 'Unidad', 'Alta'),
('GARDENING', 'Jardinería', 'Abonado y tratamiento fitosanitario', 'Córdoba', 4500, 12000, 'Servicio', 'Media'),
('GARDENING', 'Jardinería', 'Instalación de césped artificial (mano obra)', 'Andalucía', 2200, 5500, 'm2', 'Media'),
('GARDENING', 'Jardinería', 'Limpieza de parcelas/Desbroce', 'Córdoba', 150, 500, 'm2', 'Baja'),
('GARDENING', 'Jardinería', 'Plantación de setos/Arbustos (mano obra)', 'Córdoba', 500, 1500, 'Unidad', 'Baja'),
('CLEANING', 'Limpieza', 'Limpieza doméstica por horas', 'Córdoba', 1100, 1500, 'Hora', 'Baja'),
('CLEANING', 'Limpieza', 'Limpieza de cristales y persianas', 'Córdoba', 1300, 2500, 'Hora', 'Baja'),
('CLEANING', 'Limpieza', 'Limpieza fin de obra (profunda)', 'Córdoba', 14000, 45000, 'Servicio', 'Alta')
SQL);

        $this->addSql(<<<'SQL'
INSERT INTO pricing_rate (category_code, category_label, subcategory, zone, price_min, price_max, unit, complexity) VALUES
('CLEANING', 'Limpieza', 'Limpieza técnica de sofá (por plaza)', 'Córdoba', 3000, 7500, 'Plaza', 'Media'),
('CLEANING', 'Limpieza', 'Limpieza de colchón (desinfección)', 'Córdoba', 4500, 9500, 'Unidad', 'Media'),
('CLEANING', 'Limpieza', 'Limpieza de alfombras/moquetas', 'Córdoba', 800, 2000, 'm2', 'Media'),
('CLEANING', 'Limpieza', 'Tratamiento de abrillantado/pulido terrazo', 'Córdoba', 500, 1200, 'm2', 'Media'),
('DIY', 'Manitas', 'Sustitución de cinta de persiana', 'Córdoba', 3500, 6500, 'Unidad', 'Baja'),
('DIY', 'Manitas', 'Montaje de mueble tipo kit (pequeño)', 'Córdoba', 2500, 5500, 'Unidad', 'Baja'),
('DIY', 'Manitas', 'Montaje de armario grande (3+ puertas)', 'Córdoba', 6500, 16000, 'Unidad', 'Media'),
('DIY', 'Manitas', 'Instalación de soporte TV de gran formato', 'Córdoba', 3500, 7500, 'Unidad', 'Media'),
('DIY', 'Manitas', 'Colgar cortinas / estores / rieles', 'Córdoba', 2500, 5500, 'Unidad', 'Baja'),
('DIY', 'Manitas', 'Ajuste de puertas de paso/roces', 'Córdoba', 3000, 6000, 'Servicio', 'Baja'),
('DIY', 'Manitas', 'Sustitución de bombín de cerradura', 'Córdoba', 3500, 8500, 'Unidad', 'Media'),
('DIY', 'Manitas', 'Sellado de bañera/ducha con silicona', 'Córdoba', 2500, 5500, 'Servicio', 'Baja'),
('DIY', 'Manitas', 'Montaje de accesorios de baño (sin taladro)', 'Córdoba', 1500, 3500, 'Unidad', 'Baja'),
('DIY', 'Manitas', 'Reparación de persiana atascada (lamas)', 'Córdoba', 4000, 9000, 'Servicio', 'Media'),
('DIY', 'Manitas', 'Instalación de tendedero exterior', 'Córdoba', 3500, 7500, 'Unidad', 'Media'),
('DIY', 'Manitas', 'Sustitución de manivelas de puertas', 'Córdoba', 1500, 3500, 'Unidad', 'Baja'),
('APPLIANCES', 'Electrodomésticos', 'Diagnóstico de avería', 'Córdoba', 2500, 4000, 'Servicio', 'Baja'),
('APPLIANCES', 'Electrodomésticos', 'Reparación lavadora', 'Córdoba', 4000, 9000, 'Servicio', 'Media'),
('APPLIANCES', 'Electrodomésticos', 'Reparación frigorífico/nevera', 'Córdoba', 4000, 10000, 'Servicio', 'Media'),
('APPLIANCES', 'Electrodomésticos', 'Reparación lavavajillas', 'Córdoba', 4000, 9000, 'Servicio', 'Media')
SQL);

        $this->addSql(<<<'SQL'
INSERT INTO pricing_rate (category_code, category_label, subcategory, zone, price_min, price_max, unit, complexity) VALUES
('APPLIANCES', 'Electrodomésticos', 'Reparación horno/vitrocerámica', 'Córdoba', 4000, 9000, 'Servicio', 'Media'),
('APPLIANCES', 'Electrodomésticos', 'Reparación secadora', 'Córdoba', 4000, 9000, 'Servicio', 'Media'),
('APPLIANCES', 'Electrodomésticos', 'Instalación electrodoméstico empotrado', 'Córdoba', 3500, 7000, 'Instalación', 'Media'),
('MOVING', 'Mudanzas y Portes', 'Mudanza pequeña (estudio/1 habitación)', 'Córdoba', 20000, 45000, 'Servicio', 'Media'),
('MOVING', 'Mudanzas y Portes', 'Mudanza mediana (2-3 habitaciones)', 'Córdoba', 45000, 90000, 'Servicio', 'Media'),
('MOVING', 'Mudanzas y Portes', 'Mudanza grande (4+ habitaciones/chalet)', 'Córdoba', 90000, 160000, 'Servicio', 'Alta'),
('MOVING', 'Mudanzas y Portes', 'Porte de un solo mueble o electrodoméstico', 'Córdoba', 3000, 7000, 'Servicio', 'Baja'),
('MOVING', 'Mudanzas y Portes', 'Montaje y desmontaje de muebles (mudanza)', 'Córdoba', 6000, 15000, 'Servicio', 'Media'),
('MOVING', 'Mudanzas y Portes', 'Mudanza interprovincial', 'Andalucía', 60000, 150000, 'Servicio', 'Alta'),
('LOCKSMITH', 'Cerrajería', 'Apertura de puerta sin daños (horario laboral)', 'Córdoba', 5000, 10000, 'Servicio', 'Media'),
('LOCKSMITH', 'Cerrajería', 'Apertura de puerta urgente (nocturna o festivo)', 'Córdoba', 9000, 18000, 'Servicio', 'Alta'),
('LOCKSMITH', 'Cerrajería', 'Instalación cerradura de seguridad', 'Córdoba', 7000, 16000, 'Instalación', 'Alta'),
('LOCKSMITH', 'Cerrajería', 'Duplicado de llaves', 'Córdoba', 500, 1500, 'Unidad', 'Baja'),
('LOCKSMITH', 'Cerrajería', 'Apertura de caja fuerte', 'Córdoba', 8000, 20000, 'Servicio', 'Alta'),
('POOL', 'Mantenimiento de Piscinas', 'Mantenimiento mensual (piscina residencial)', 'Córdoba', 6000, 12000, 'Servicio', 'Media'),
('POOL', 'Mantenimiento de Piscinas', 'Visita puntual de limpieza', 'Córdoba', 2500, 6000, 'Servicio', 'Baja'),
('POOL', 'Mantenimiento de Piscinas', 'Apertura de temporada', 'Córdoba', 6000, 12000, 'Servicio', 'Media'),
('POOL', 'Mantenimiento de Piscinas', 'Cierre e invernaje de temporada', 'Córdoba', 5000, 10000, 'Servicio', 'Media'),
('POOL', 'Mantenimiento de Piscinas', 'Reparación de bomba o filtro', 'Córdoba', 8000, 18000, 'Servicio', 'Alta'),
('POOL', 'Mantenimiento de Piscinas', 'Tratamiento de agua verde', 'Córdoba', 5000, 12000, 'Servicio', 'Media')
SQL);

        $this->addSql(<<<'SQL'
INSERT INTO pricing_rate (category_code, category_label, subcategory, zone, price_min, price_max, unit, complexity) VALUES
('MASONRY', 'Albañilería', 'Reforma de baño pequeña (llave en mano)', 'Córdoba', 250000, 450000, 'Servicio', 'Alta'),
('MASONRY', 'Albañilería', 'Reforma de cocina pequeña (llave en mano)', 'Córdoba', 300000, 600000, 'Servicio', 'Alta'),
('MASONRY', 'Albañilería', 'Tabiquería de pladur', 'Córdoba', 2500, 4500, 'm2', 'Media'),
('MASONRY', 'Albañilería', 'Sellado de grietas y fisuras', 'Córdoba', 3000, 8000, 'Servicio', 'Baja'),
('MASONRY', 'Albañilería', 'Tratamiento de humedades por capilaridad (inyección química)', 'Córdoba', 20000, 60000, 'Servicio', 'Alta'),
('CLEANING', 'Limpieza', 'Plancha a domicilio', 'Córdoba', 1200, 2000, 'Hora', 'Baja'),
('CLEANING', 'Limpieza', 'Limpieza profunda de mantenimiento (vivienda habitada)', 'Córdoba', 5000, 9000, 'Servicio', 'Media'),
('CLEANING', 'Limpieza', 'Limpieza de oficinas y negocios', 'Córdoba', 4000, 9000, 'Servicio', 'Media'),
('CLEANING', 'Limpieza', 'Limpieza de alquiler turístico (check-out)', 'Córdoba', 3500, 7000, 'Servicio', 'Media'),
('CLEANING', 'Limpieza', 'Limpieza de exteriores', 'Córdoba', 3000, 7000, 'Servicio', 'Media'),
('SEWING', 'Costura y Arreglos', 'Bajo de pantalón o dobladillo', 'Córdoba', 800, 1500, 'Unidad', 'Baja'),
('SEWING', 'Costura y Arreglos', 'Ajuste de cintura o entallado', 'Córdoba', 1200, 2500, 'Unidad', 'Media'),
('SEWING', 'Costura y Arreglos', 'Cambio de cremallera', 'Córdoba', 1000, 2000, 'Unidad', 'Baja'),
('SEWING', 'Costura y Arreglos', 'Arreglo de forro o costuras rotas', 'Córdoba', 800, 1800, 'Unidad', 'Baja'),
('SEWING', 'Costura y Arreglos', 'Confección o arreglo a medida (prenda completa)', 'Córdoba', 3000, 8000, 'Servicio', 'Alta'),
('BLINDS', 'Persianas y Toldos', 'Reparación de persiana eléctrica o motor', 'Córdoba', 5000, 12000, 'Servicio', 'Media'),
('BLINDS', 'Persianas y Toldos', 'Instalación de toldo', 'Córdoba', 15000, 40000, 'Instalación', 'Media'),
('BLINDS', 'Persianas y Toldos', 'Instalación de mosquitera', 'Córdoba', 3000, 8000, 'Instalación', 'Baja'),
('GLAZING', 'Cristalería', 'Sustitución de cristal de ventana', 'Córdoba', 4000, 12000, 'Servicio', 'Media'),
('GLAZING', 'Cristalería', 'Instalación de doble acristalamiento (por hoja)', 'Córdoba', 8000, 20000, 'Instalación', 'Media')
SQL);

        $this->addSql(<<<'SQL'
INSERT INTO pricing_rate (category_code, category_label, subcategory, zone, price_min, price_max, unit, complexity) VALUES
('GLAZING', 'Cristalería', 'Reparación de mampara de ducha', 'Córdoba', 5000, 12000, 'Servicio', 'Media'),
('GLAZING', 'Cristalería', 'Corte de cristal a medida', 'Córdoba', 2000, 6000, 'm2', 'Baja'),
('FURNITURE', 'Restauración de Muebles', 'Restauración de mueble de madera', 'Córdoba', 6000, 20000, 'Servicio', 'Alta'),
('FURNITURE', 'Restauración de Muebles', 'Retapizado de silla o sillón', 'Córdoba', 5000, 15000, 'Servicio', 'Media'),
('FURNITURE', 'Restauración de Muebles', 'Retapizado de sofá', 'Córdoba', 15000, 40000, 'Servicio', 'Alta'),
('FURNITURE', 'Restauración de Muebles', 'Barnizado o lacado de mueble', 'Córdoba', 4000, 12000, 'Servicio', 'Media'),
('CLEAROUT', 'Vaciado de Pisos', 'Vaciado de piso pequeño (estudio/1 habitación)', 'Córdoba', 30000, 60000, 'Servicio', 'Media'),
('CLEAROUT', 'Vaciado de Pisos', 'Vaciado de piso completo (2-3 habitaciones)', 'Córdoba', 60000, 120000, 'Servicio', 'Alta'),
('CLEAROUT', 'Vaciado de Pisos', 'Vaciado de trastero o garaje', 'Córdoba', 10000, 30000, 'Servicio', 'Baja'),
('CLEAROUT', 'Vaciado de Pisos', 'Organización y orden del hogar (por hora)', 'Córdoba', 3500, 6000, 'Hora', 'Media'),
('CLEAROUT', 'Vaciado de Pisos', 'Organización de armarios o despensa (servicio completo)', 'Córdoba', 8000, 18000, 'Servicio', 'Media'),
('PEST_CONTROL', 'Control de Plagas', 'Desinsectación (cucarachas, chinches, hormigas)', 'Córdoba', 6000, 12000, 'Servicio', 'Media'),
('PEST_CONTROL', 'Control de Plagas', 'Desratización', 'Córdoba', 6000, 14000, 'Servicio', 'Media'),
('PEST_CONTROL', 'Control de Plagas', 'Fumigación preventiva anual', 'Córdoba', 8000, 16000, 'Servicio', 'Media'),
('PEST_CONTROL', 'Control de Plagas', 'Tratamiento contra carcoma o termitas', 'Córdoba', 15000, 40000, 'Servicio', 'Alta'),
('SMART_HOME', 'Domótica y Seguridad', 'Instalación de cerradura inteligente', 'Córdoba', 8000, 18000, 'Instalación', 'Media'),
('SMART_HOME', 'Domótica y Seguridad', 'Instalación de cámara de videovigilancia (unidad)', 'Córdoba', 5000, 12000, 'Instalación', 'Media'),
('SMART_HOME', 'Domótica y Seguridad', 'Instalación de sistema de alarma básico', 'Córdoba', 15000, 35000, 'Instalación', 'Alta'),
('SMART_HOME', 'Domótica y Seguridad', 'Configuración de asistente de voz o ecosistema domótico', 'Córdoba', 4000, 9000, 'Servicio', 'Baja'),
('HVAC', 'Climatización', 'Instalación de aerotermia completa (equipo + instalación)', 'Córdoba', 800000, 1200000, 'Instalación', 'Alta')
SQL);

        $this->addSql(<<<'SQL'
INSERT INTO pricing_rate (category_code, category_label, subcategory, zone, price_min, price_max, unit, complexity) VALUES
('HVAC', 'Climatización', 'Mantenimiento anual de bomba de aerotermia', 'Córdoba', 8000, 15000, 'Servicio', 'Media'),
('BEAUTY', 'Belleza', 'Depilación con cera (piernas completas)', 'Córdoba', 1500, 3000, 'Servicio', 'Baja'),
('BEAUTY', 'Belleza', 'Manicura y pedicura completa', 'Córdoba', 2000, 4000, 'Servicio', 'Baja'),
('BEAUTY', 'Belleza', 'Peluquería a domicilio (corte y peinado)', 'Córdoba', 2000, 4500, 'Servicio', 'Media'),
('BEAUTY', 'Belleza', 'Maquillaje para evento', 'Córdoba', 3500, 8000, 'Servicio', 'Media'),
('BEAUTY', 'Belleza', 'Tratamiento facial de limpieza', 'Córdoba', 3000, 6000, 'Servicio', 'Media'),
('PETS', 'Mascotas', 'Paseo de perros (por sesión)', 'Córdoba', 800, 1500, 'Servicio', 'Baja'),
('PETS', 'Mascotas', 'Cuidado de mascotas a domicilio (por día)', 'Córdoba', 1500, 3000, 'Servicio', 'Media'),
('PETS', 'Mascotas', 'Peluquería canina', 'Córdoba', 2500, 5000, 'Servicio', 'Media'),
('CARE', 'Cuidados', 'Cuidado de niños (canguro, por hora)', 'Córdoba', 800, 1400, 'Hora', 'Baja'),
('CARE', 'Cuidados', 'Cuidado de personas mayores (por hora)', 'Córdoba', 1000, 1600, 'Hora', 'Media')
SQL);

    }

    public function down(Schema $schema): void
    {
        // Irreversible data reload; restore from Version20260729120000 seed if needed.
        $this->addSql('DELETE FROM pricing_rate');
    }
}
