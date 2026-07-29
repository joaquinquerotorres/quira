<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260729120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'pricing_rate catalog (data from former gemini_pricing.csv) + harden gemini_cache';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE pricing_rate (id INT AUTO_INCREMENT NOT NULL, category_code VARCHAR(50) NOT NULL, category_label VARCHAR(100) NOT NULL, subcategory VARCHAR(255) NOT NULL, zone VARCHAR(50) NOT NULL, price_min INT NOT NULL, price_max INT NOT NULL, unit VARCHAR(50) NOT NULL, complexity VARCHAR(20) NOT NULL, INDEX idx_pricing_rate_zone (zone), INDEX idx_pricing_rate_category_code (category_code), UNIQUE INDEX uniq_pricing_rate_cat_sub_zone (category_label, subcategory, zone), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('ALTER TABLE gemini_cache ADD content_hash VARCHAR(64) NOT NULL DEFAULT \'\'');
        $this->addSql('ALTER TABLE gemini_cache ADD zone_key VARCHAR(120) NOT NULL DEFAULT \'\'');
        $this->addSql('ALTER TABLE gemini_cache CHANGE model model VARCHAR(80) NOT NULL');
        $this->addSql('CREATE INDEX idx_gemini_cache_lookup ON gemini_cache (model, content_hash, expires_at)');
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
('DIY', 'Manitas', 'Sustitución de manivelas de puertas', 'Córdoba', 1500, 3500, 'Unidad', 'Baja')
SQL);

    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE pricing_rate');
        $this->addSql('DROP INDEX idx_gemini_cache_lookup ON gemini_cache');
        $this->addSql('ALTER TABLE gemini_cache DROP content_hash');
        $this->addSql('ALTER TABLE gemini_cache DROP zone_key');
        $this->addSql('ALTER TABLE gemini_cache CHANGE model model VARCHAR(50) NOT NULL');
    }
}
