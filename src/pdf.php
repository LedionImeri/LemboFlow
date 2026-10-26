<?php
/**
 * Invoice PDFs with Dompdf.
 *
 * Security: remote resources and PHP in templates are disabled, and Dompdf is
 * chrooted to the project. The logo is read by us (only from uploads/logos/)
 * and embedded as a data URI, so the renderer never touches the filesystem or
 * the network on its own.
 */

declare(strict_types=1);

require_once BASE_PATH . '/vendor/autoload.php';
require_once __DIR__ . '/invoices.php';
require_once __DIR__ . '/settings.php';

use Dompdf\Dompdf;
use Dompdf\Options;

/** Render an invoice to PDF bytes. */
function render_invoice_pdf(int $userId, array $invoice): string
{
    $profile = get_business_profile($userId);
    $items   = invoice_items((int) $invoice['id']);

    $html = render_template('pdf/invoice.php', [
        'invoice' => $invoice,
        'items'   => $items,
        'profile' => $profile,
        'logo'    => logo_data_uri($profile['logo_path']),
    ]);

    $options = new Options();
    $options->setIsRemoteEnabled(false);
    $options->setIsPhpEnabled(false);
    $options->setIsJavascriptEnabled(false);
    $options->setChroot(BASE_PATH);
    $options->setDefaultFont('DejaVu Sans');
    $options->setDefaultPaperSize('a4');

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->render();

    // Page numbers on every page, bottom right (business details are on the left, in the template).
    $canvas = $dompdf->getCanvas();
    $font   = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
    $label  = 'Page {PAGE_NUM} of {PAGE_COUNT}';
    $size   = 5.6; // matches the template's 7.5px footer (Dompdf renders at 96 dpi: 1px = 0.75pt)
    $width  = $dompdf->getFontMetrics()->getTextWidth('Page 99 of 99', $font, $size);
    $canvas->page_text(
        $canvas->get_width() - 42 - $width,
        $canvas->get_height() - 30,
        $label,
        $font,
        $size,
        [0.55, 0.58, 0.61]
    );

    $dompdf->addInfo('Title', 'Invoice ' . $invoice['invoice_number']);
    $dompdf->addInfo('Author', $profile['business_name']);
    $dompdf->addInfo('Creator', 'LemboFlow');

    return $dompdf->output();
}

/** Include a template from /templates with the given variables and return its HTML. */
function render_template(string $template, array $vars): string
{
    extract($vars, EXTR_SKIP);
    ob_start();
    require BASE_PATH . '/templates/' . $template;
    return (string) ob_get_clean();
}

/** The business logo as a data: URI, or null when there is none (or GD is missing). */
function logo_data_uri(?string $relativePath): ?string
{
    if (!$relativePath || !extension_loaded('gd')) {
        return null;
    }

    $dir  = realpath(BASE_PATH . '/uploads/logos');
    $file = realpath(BASE_PATH . '/' . $relativePath);
    if (!$dir || !$file || !str_starts_with($file, $dir . DIRECTORY_SEPARATOR) || !is_file($file)) {
        return null;
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file);
    if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
        return null;
    }

    return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($file));
}

/** Safe download name: "INV-2026-0001.pdf". */
function invoice_pdf_filename(array $invoice): string
{
    return preg_replace('/[^A-Za-z0-9._-]/', '_', $invoice['invoice_number']) . '.pdf';
}
