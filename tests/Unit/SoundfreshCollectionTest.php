<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SoundfreshCollectionTest extends TestCase
{
    #[Test]
    public function collection_does_not_disable_pagination_with_unsupported_show_all_length(): void
    {
        $source = file_get_contents(base_path('automation-worker/src/server.ts'));

        $this->assertStringNotContainsString('DataTable().page.len(-1).draw()', $source);
        $this->assertStringContainsString('const largest = values', $source);
        $this->assertStringContainsString('await lengthSelect.first().selectOption(String(largest))', $source);
        $this->assertStringContainsString('while (items.length < max)', $source);
        $this->assertStringContainsString('dataTable.page(info.page + 1).draw("page")', $source);
        $this->assertStringContainsString('Dimensi Suara page advanced through DataTables API', $source);
        $this->assertStringContainsString('Dimensi Suara DataTables API unavailable; next-page control fallback applied', $source);
    }
}
