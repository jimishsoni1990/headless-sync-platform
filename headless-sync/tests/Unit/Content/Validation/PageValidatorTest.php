<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content\Validation;

use HSP\Modules\Content\Validation\PageValidator;
use HSP\Modules\Content\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

final class PageValidatorTest extends TestCase
{
    private PageValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new PageValidator();
    }

    private function validRaw(array $overrides = []): array
    {
        return array_merge([
            'ID'                => 7,
            'post_type'         => 'page',
            'post_name'         => 'about-us',
            'post_status'       => 'publish',
            'post_title'        => 'About Us',
            'post_content'      => '<p>About</p>',
            'post_date_gmt'     => '2024-03-01 08:00:00',
            'post_modified_gmt' => '2024-03-02 09:00:00',
            'post_parent'       => 0,
            'menu_order'        => 0,
        ], $overrides);
    }

    public function test_valid_page_passes(): void
    {
        $this->expectNotToPerformAssertions();
        $this->validator->validate($this->validRaw());
    }

    public function test_missing_id_throws(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches("/ID/");
        $this->validator->validate($this->validRaw(['ID' => null]));
    }

    public function test_zero_id_throws(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate($this->validRaw(['ID' => 0]));
    }

    public function test_missing_slug_throws(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches("/post_name/");
        $this->validator->validate($this->validRaw(['post_name' => '']));
    }

    public function test_missing_status_throws(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches("/post_status/");
        $this->validator->validate($this->validRaw(['post_status' => '   ']));
    }

    public function test_wrong_post_type_throws(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches("/post_type/");
        $this->validator->validate($this->validRaw(['post_type' => 'post']));
    }

    public function test_missing_post_type_field_is_not_an_error(): void
    {
        $this->expectNotToPerformAssertions();
        $raw = $this->validRaw();
        unset($raw['post_type']);
        $this->validator->validate($raw);
    }

    public function test_invalid_date_throws(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches("/post_date_gmt/");
        $this->validator->validate($this->validRaw(['post_date_gmt' => 'garbage']));
    }

    public function test_zero_datetime_sentinel_accepted(): void
    {
        $this->expectNotToPerformAssertions();
        $this->validator->validate($this->validRaw(['post_modified_gmt' => '0000-00-00 00:00:00']));
    }

    public function test_violations_list_contains_all_failures(): void
    {
        try {
            $this->validator->validate($this->validRaw(['ID' => -1, 'post_name' => '', 'post_status' => '']));
            $this->fail('Expected ValidationException.');
        } catch (ValidationException $e) {
            $this->assertGreaterThanOrEqual(3, count($e->getViolations()));
        }
    }
}
