<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content\Validation;

use HSP\Modules\Content\Validation\CategoryValidator;
use HSP\Modules\Content\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

final class CategoryValidatorTest extends TestCase
{
    private CategoryValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new CategoryValidator();
    }

    private function validRaw(array $overrides = []): array
    {
        return array_merge([
            'term_id'     => 3,
            'name'        => 'Technology',
            'slug'        => 'technology',
            'description' => 'Tech articles',
            'parent'      => 0,
            'count'       => 5,
            'taxonomy'    => 'category',
        ], $overrides);
    }

    public function test_valid_term_passes(): void
    {
        $this->expectNotToPerformAssertions();
        $this->validator->validate($this->validRaw());
    }

    public function test_missing_term_id_throws(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches("/term_id/");
        $this->validator->validate($this->validRaw(['term_id' => null]));
    }

    public function test_zero_term_id_throws(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate($this->validRaw(['term_id' => 0]));
    }

    public function test_negative_term_id_throws(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate($this->validRaw(['term_id' => -1]));
    }

    public function test_missing_name_throws(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches("/name/");
        $this->validator->validate($this->validRaw(['name' => '']));
    }

    public function test_whitespace_only_name_throws(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate($this->validRaw(['name' => '   ']));
    }

    public function test_missing_slug_throws(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches("/slug/");
        $this->validator->validate($this->validRaw(['slug' => '']));
    }

    public function test_wrong_taxonomy_throws(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches("/taxonomy/");
        $this->validator->validate($this->validRaw(['taxonomy' => 'tag']));
    }

    public function test_taxonomy_field_absent_is_not_an_error(): void
    {
        $this->expectNotToPerformAssertions();
        $raw = $this->validRaw();
        unset($raw['taxonomy']);
        $this->validator->validate($raw);
    }

    public function test_empty_description_is_valid(): void
    {
        $this->expectNotToPerformAssertions();
        $this->validator->validate($this->validRaw(['description' => '']));
    }

    public function test_violations_list_populated_on_multiple_failures(): void
    {
        try {
            $this->validator->validate($this->validRaw(['term_id' => 0, 'name' => '', 'slug' => '']));
            $this->fail('Expected ValidationException.');
        } catch (ValidationException $e) {
            $this->assertCount(3, $e->getViolations());
        }
    }
}
