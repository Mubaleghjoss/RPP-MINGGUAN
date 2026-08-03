<?php

namespace Tests\Feature;

use Tests\TestCase;

class CurriculumSourceIntegrityTest extends TestCase
{
    public function test_extracted_source_has_exactly_seventeen_levels_and_thirty_four_documents(): void
    {
        $data = json_decode(file_get_contents(database_path('data/curriculum.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertCount(17, $data['levels']);
        $this->assertCount(34, $data['documents']);
        $this->assertSame(17, count(array_unique(array_column($data['documents'], 'level_code'))));
        $this->assertSame(['ggb', 'silabus'], array_values(array_unique(array_column($data['documents'], 'type'))));
        $this->assertContains('PM-1', array_column($data['levels'], 'code'));
        $this->assertContains('PM-4', array_column($data['levels'], 'code'));

        foreach (array_merge($data['ggb_items'], $data['syllabus_items']) as $item) {
            $this->assertNotEmpty($item['level_code']);
            $this->assertNotEmpty($item['document_key']);
            $this->assertGreaterThan(0, $item['source_page']);
            $this->assertGreaterThan(0, $item['sort_order']);
        }
    }
}
