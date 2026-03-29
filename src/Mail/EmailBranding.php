<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Logo en correos: URL pública (p. ej. Supabase Storage con bucket público).
 */
final class EmailBranding
{
    public function __construct(
        private readonly string $logoPublicUrl,
    ) {
    }

    public function headerLogoImgTag(): string
    {
        $src = htmlspecialchars($this->logoPublicUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return '<img src="'.$src.'" alt="Quira" width="38" height="38" style="height:38px;width:38px;border-radius:9px;display:block;margin:0 auto;">';
    }
}
