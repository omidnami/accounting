<?php

use App\Support\Digits;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const string TRIGGER_MESSAGE = 'products_identifier_unique: product code or barcode conflicts with another product';

    public function up(): void
    {
        $this->assertNoIdentifierCollisions();

        match (DB::getDriverName()) {
            'sqlite' => $this->createSqliteTriggers(),
            'mysql', 'mariadb' => $this->createMySqlTriggers(),
            default => throw new RuntimeException('Cross-column product identifier uniqueness is not supported by this database driver.'),
        };
    }

    public function down(): void
    {
        match (DB::getDriverName()) {
            'sqlite', 'mysql', 'mariadb' => $this->dropTriggers(),
            default => throw new RuntimeException('Cross-column product identifier uniqueness is not supported by this database driver.'),
        };
    }

    private function assertNoIdentifierCollisions(): void
    {
        $claims = [];

        foreach (DB::table('products')
            ->select(['id', 'code', 'barcode'])
            ->orderBy('id')
            ->cursor() as $product) {
            foreach (['code', 'barcode'] as $field) {
                $value = $product->{$field};

                if ($value === null) {
                    continue;
                }

                $identifier = trim(Digits::toLatin((string) $value));

                if ($identifier === '') {
                    continue;
                }

                $claims[$identifier] ??= [];

                // code == barcode on the same product is a single valid claim.
                if (isset($claims[$identifier][$product->id])) {
                    continue;
                }

                foreach ($claims[$identifier] as $otherId => $other) {
                    if ($otherId === $product->id) {
                        continue;
                    }

                    throw new RuntimeException(
                        "Product identifier collision for normalized value '{$identifier}': "
                        ."product #{$product->id} {$field}='{$value}' conflicts with "
                        ."product #{$otherId} {$other['field']}='{$other['value']}'. "
                        .'Resolve legacy product data manually before enforcing uniqueness; no product data was changed.',
                    );
                }

                $claims[$identifier][$product->id] = [
                    'field' => $field,
                    'value' => (string) $value,
                ];
            }
        }
    }

    private function createSqliteTriggers(): void
    {
        $code = $this->normalizedSql('code');
        $barcode = $this->normalizedSql('barcode');
        $insertConflict = $this->conflictCondition($code, $barcode, 'NEW.code', 'NEW.barcode');
        $updateConflict = $this->conflictCondition($code, $barcode, 'NEW.code', 'NEW.barcode', 'OLD.id');

        DB::connection()->getPdo()->exec(<<<SQL
            CREATE TRIGGER products_identifier_unique_insert
            BEFORE INSERT ON products
            WHEN EXISTS (SELECT 1 FROM products WHERE {$insertConflict})
            BEGIN
                SELECT RAISE(ABORT, '{$this->triggerMessage()}');
            END
            SQL);
        DB::connection()->getPdo()->exec(<<<SQL
            CREATE TRIGGER products_identifier_unique_update
            BEFORE UPDATE ON products
            WHEN EXISTS (SELECT 1 FROM products WHERE {$updateConflict})
            BEGIN
                SELECT RAISE(ABORT, '{$this->triggerMessage()}');
            END
            SQL);
    }

    private function createMySqlTriggers(): void
    {
        $code = $this->normalizedSql('code');
        $barcode = $this->normalizedSql('barcode');
        $insertConflict = $this->conflictCondition($code, $barcode, 'NEW.code', 'NEW.barcode');
        $updateConflict = $this->conflictCondition($code, $barcode, 'NEW.code', 'NEW.barcode', 'OLD.id');

        DB::connection()->getPdo()->exec(<<<SQL
            CREATE TRIGGER products_identifier_unique_insert
            BEFORE INSERT ON products
            FOR EACH ROW
            BEGIN
                IF EXISTS (SELECT 1 FROM products WHERE {$insertConflict}) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$this->triggerMessage()}';
                END IF;
            END
            SQL);
        DB::connection()->getPdo()->exec(<<<SQL
            CREATE TRIGGER products_identifier_unique_update
            BEFORE UPDATE ON products
            FOR EACH ROW
            BEGIN
                IF EXISTS (SELECT 1 FROM products WHERE {$updateConflict}) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$this->triggerMessage()}';
                END IF;
            END
            SQL);
    }

    /**
     * @return string SQL condition comparing both identifier columns in both directions.
     */
    private function conflictCondition(
        string $code,
        string $barcode,
        string $newCode,
        string $newBarcode,
        ?string $excludedId = null,
    ): string {
        $newCode = $this->normalizedSql($newCode);
        $newBarcode = $this->normalizedSql($newBarcode);
        $condition = "{$barcode} = {$newCode}"
            ." OR {$code} = {$newCode}"
            ." OR {$barcode} = {$newBarcode}"
            ." OR {$code} = {$newBarcode}";

        return $excludedId === null
            ? $condition
            : "id <> {$excludedId} AND ({$condition})";
    }

    private function normalizedSql(string $column): string
    {
        $expression = $column;

        foreach ([
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ] as $digit => $replacement) {
            $expression = "REPLACE({$expression}, '{$digit}', '{$replacement}')";
        }

        return "TRIM({$expression})";
    }

    private function triggerMessage(): string
    {
        return self::TRIGGER_MESSAGE;
    }

    private function dropTriggers(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS products_identifier_unique_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS products_identifier_unique_update');
    }
};
