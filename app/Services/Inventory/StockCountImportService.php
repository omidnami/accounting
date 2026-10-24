<?php

namespace App\Services\Inventory;

use App\Models\Product;
use App\Support\Decimal;
use App\Support\Digits;
use DomainException;
use Illuminate\Http\UploadedFile;

class StockCountImportService
{
    /**
     * @return array<int, array{
     *     product_id: int, code: string, name: string, unit: string,
     *     counted_quantity: string, unit_cost: string|null
     * }>
     */
    public function parse(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'rb');

        if ($handle === false) {
            throw new DomainException('فایل شمارش قابل خواندن نیست.');
        }

        try {
            $firstLine = fgets($handle);

            if ($firstLine === false) {
                throw new DomainException('فایل شمارش خالی است.');
            }

            $delimiter = $this->delimiter($firstLine);
            rewind($handle);
            $headers = $this->headers(fgets($handle) ?: '', $delimiter);
            $columns = $this->columnMap($headers);
            $rows = [];
            $line = 1;

            while (($values = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                $line++;

                if ($values === [null] || trim(implode('', $values)) === '') {
                    continue;
                }

                if (count($rows) >= 500) {
                    throw new DomainException('حداکثر ۵۰۰ کالا در هر فایل قابل ورود است.');
                }

                $code = trim((string) ($values[$columns['code']] ?? ''));
                $quantity = trim((string) ($values[$columns['quantity']] ?? ''));
                $unitCost = isset($columns['unit_cost'])
                    ? trim((string) ($values[$columns['unit_cost']] ?? ''))
                    : '';

                if ($code === '' || $quantity === '') {
                    throw new DomainException("خط {$line}: کد کالا و مقدار شمارش الزامی است.");
                }

                $quantity = Digits::normalizeNumber($quantity);
                $normalizedQuantity = Decimal::normalize($quantity, Decimal::QUANTITY_SCALE);

                if (Decimal::compare($normalizedQuantity, '0', Decimal::QUANTITY_SCALE) < 0) {
                    throw new DomainException("خط {$line}: مقدار شمارش نمی‌تواند منفی باشد.");
                }

                $normalizedCost = null;

                if ($unitCost !== '') {
                    $normalizedCost = Decimal::normalize(
                        Digits::normalizeNumber($unitCost),
                        Decimal::UNIT_COST_SCALE,
                    );

                    if (Decimal::compare($normalizedCost, '0', Decimal::UNIT_COST_SCALE) < 0) {
                        throw new DomainException("خط {$line}: بهای واحد نمی‌تواند منفی باشد.");
                    }
                }

                $product = Product::query()
                    ->where('is_active', true)
                    ->where(fn ($query) => $query
                        ->where('code', Digits::toLatin($code))
                        ->orWhere('barcode', Digits::toLatin($code)))
                    ->first();

                if (! $product) {
                    throw new DomainException("خط {$line}: کالای «{$code}» پیدا نشد.");
                }

                if (collect($rows)->contains('product_id', $product->id)) {
                    throw new DomainException("خط {$line}: کالای «{$code}» تکراری است.");
                }

                $rows[] = [
                    'product_id' => $product->id,
                    'code' => $product->code,
                    'name' => $product->name,
                    'unit' => $product->unit,
                    'counted_quantity' => $normalizedQuantity,
                    'unit_cost' => $normalizedCost,
                ];
            }

            if ($rows === []) {
                throw new DomainException('هیچ ردیف معتبری در فایل پیدا نشد.');
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    private function delimiter(string $line): string
    {
        $delimiters = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t")];
        arsort($delimiters);

        return (string) array_key_first($delimiters);
    }

    /**
     * @return array<int, string>
     */
    private function headers(string $line, string $delimiter): array
    {
        $headers = str_getcsv($line, $delimiter, '"', '');

        return array_map(
            fn ($value): string => strtolower(trim((string) $value, "\xEF\xBB\xBF \t\n\r\0\x0B")),
            $headers,
        );
    }

    /**
     * @param  array<int, string>  $headers
     * @return array{code: int, quantity: int, unit_cost?: int}
     */
    private function columnMap(array $headers): array
    {
        $aliases = [
            'code' => ['code', 'sku', 'کد', 'کد کالا', 'بارکد', 'barcode'],
            'quantity' => ['quantity', 'counted_quantity', 'مقدار', 'موجودی', 'مقدار شمارش'],
            'unit_cost' => ['unit_cost', 'cost', 'بهای واحد', 'قیمت'],
        ];
        $map = [];

        foreach ($aliases as $key => $names) {
            $index = array_search(true, array_map(
                fn (string $header): bool => in_array($header, $names, true),
                $headers,
            ), true);

            if ($index !== false) {
                $map[$key] = $index;
            }
        }

        if (! isset($map['code'], $map['quantity'])) {
            throw new DomainException('ستون‌های code و quantity در سربرگ فایل الزامی‌اند.');
        }

        return $map;
    }
}
