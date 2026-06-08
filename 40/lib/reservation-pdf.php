<?php
declare(strict_types=1);

/** PDF mínimo (tablas + texto) para reporte de reservas. Sin dependencias externas. */
final class ReservationPdf
{
    /** @var list<list<string>> */
    private array $pages = [[]];

    private float $y = 780.0;

    private const MARGIN = 48.0;
    private const LINE = 14.0;

    public function addTitle(string $text, int $size = 16): void
    {
        $this->ensureSpace(self::LINE * 2);
        $this->pageLine(sprintf('BT /F2 %d Tf %.2F %.2F Td (%s) Tj ET', $size, self::MARGIN, $this->y, $this->escape($text)));
        $this->y -= self::LINE * 1.8;
    }

    public function addText(string $text, int $size = 10): void
    {
        $this->ensureSpace(self::LINE);
        $this->pageLine(sprintf('BT /F1 %d Tf %.2F %.2F Td (%s) Tj ET', $size, self::MARGIN, $this->y, $this->escape($text)));
        $this->y -= self::LINE;
    }

    /** @param list<string> $cells */
    public function addTableRow(array $cells, array $widths, bool $header = false, int $size = 9): void
    {
        $this->ensureSpace(self::LINE * 1.2);
        $font = $header ? 'F2' : 'F1';
        $x = self::MARGIN;
        foreach ($cells as $i => $cell) {
            $this->pageLine(sprintf(
                'BT /%s %d Tf %.2F %.2F Td (%s) Tj ET',
                $font,
                $size,
                $x,
                $this->y,
                $this->escape((string) $cell)
            ));
            $x += $widths[$i] ?? 100;
        }
        $this->y -= self::LINE * 1.15;
        if ($header) {
            $this->pageLine(sprintf(
                '%.2F %.2F m %.2F %.2F l S',
                self::MARGIN,
                $this->y + 4,
                self::MARGIN + array_sum($widths),
                $this->y + 4
            ));
            $this->y -= 4;
        }
    }

    public function output(string $filename): void
    {
        $pageCount = count($this->pages);
        $catalogNum = 1;
        $pagesNum = 2;
        $fontRegularNum = 3;
        $fontBoldNum = 4;
        $nextNum = 5;
        $pageObjectNums = [];
        $contentObjectNums = [];

        for ($p = 0; $p < $pageCount; $p++) {
            $pageObjectNums[] = $nextNum++;
            $contentObjectNums[] = $nextNum++;
        }

        /** @var array<int, string> $objects */
        $objects = [];
        $objects[$catalogNum] = "<< /Type /Catalog /Pages {$pagesNum} 0 R >>";
        $kids = implode(' ', array_map(static fn(int $n): string => "{$n} 0 R", $pageObjectNums));
        $objects[$pagesNum] = "<< /Type /Pages /Kids [{$kids}] /Count {$pageCount} >>";
        $objects[$fontRegularNum] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[$fontBoldNum] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        for ($p = 0; $p < $pageCount; $p++) {
            $pageNum = $pageObjectNums[$p];
            $contentNum = $contentObjectNums[$p];
            $stream = implode("\n", $this->pages[$p]);
            $objects[$pageNum] = "<< /Type /Page /Parent {$pagesNum} 0 R /MediaBox [0 0 612 792] "
                . "/Resources << /Font << /F1 {$fontRegularNum} 0 R /F2 {$fontBoldNum} 0 R >> >> "
                . "/Contents {$contentNum} 0 R >>";
            $objects[$contentNum] = "<< /Length " . strlen($stream) . " >>\nstream\n{$stream}\nendstream";
        }

        $total = $nextNum - 1;
        $pdf = "%PDF-1.4\n";
        $offsets = array_fill(0, $total + 1, 0);

        for ($i = 1; $i <= $total; $i++) {
            $offsets[$i] = strlen($pdf);
            $body = $objects[$i] ?? '<< >>';
            $pdf .= "{$i} 0 obj\n{$body}\nendobj\n";
        }

        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 " . ($total + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $total; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size " . ($total + 1) . " /Root {$catalogNum} 0 R >>\nstartxref\n{$xrefPos}\n%%EOF";

        if (headers_sent($file, $line)) {
            throw new RuntimeException("Headers already sent at {$file}:{$line}");
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
    }

    private function pageLine(string $line): void
    {
        $this->pages[count($this->pages) - 1][] = $line;
    }

    private function ensureSpace(float $needed): void
    {
        if ($this->y - $needed >= self::MARGIN) {
            return;
        }
        $this->pages[] = [];
        $this->y = 780.0;
    }

    private function escape(string $text): string
    {
        $text = $this->toWinAnsi($text);
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    private function toWinAnsi(string $text): string
    {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
        return $converted !== false ? $converted : $text;
    }
}
