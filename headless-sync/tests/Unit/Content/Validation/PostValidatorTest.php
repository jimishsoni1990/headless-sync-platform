<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content\Validation;

use HSP\Modules\Content\Validation\PostValidator;
use HSP\Modules\Content\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

final class PostValidatorTest extends TestCase
{
    private PostValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new PostValidator();
    }

    private function validRaw(array $overrides = []): array
    {
        return array_merge([
            'ID'                => 42,
            'post_type'         => 'post',
            'post_name'         => 'hello-world',
            'post_status'       => 'publish',
            'post_title'        => 'Hello World',
            'post_content'      => '<p>Content</p>',
            'post_excerpt'      => 'Summary',
            'post_date_gmt'     => '2024-01-15 10:00:00',
            'post_modified_gmt' => '2024-01-16 12:00:00',
            'post_author'       => '1',
        ], $overrides);
    }

    public function test_valid_post_passes(): void
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

    public function test_negative_id_throws(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate($this->validRaw(['ID' => -5]));
    }

    public function test_missing_post_name_throws(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches("/post_name/");
        $this->validator->validate($this->validRaw(['post_name' => '']));
    }

    public function test_missing_post_status_throws(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches("/post_status/");
        $this->validator->validate($this->validRaw(['post_status' => '']));
    }

    public function test_wrong_post_type_throws(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches("/post_type/");
        $this->validator->validate($this->validRaw(['post_type' => 'page']));
    }

    public function test_violations_list_populated(): void
    {
        try {
            $this->validator->validate($this->validRaw(['ID' => 0, 'post_name' => '']));
            $this->fail('Expected ValidationException not thrown.');
        } catch (ValidationException $e) {
            $this->assertCount(2, $e->getViolations());
        }
    }

    public function test_missing_post_type_field_is_not_an_error(): void
    {
        // post_type absent entirely is not validated (field may be omitted by caller).
        $this->expectNotToPerformAssertions();
        $raw = $this->validRaw();
        unset($raw['post_type']);
        $this->validator->validate($raw);
    }

    public function test_zero_datetime_sentinel_is_accepted(): void
    {
        $this->expectNotToPerformAssertions();
        $this->validator->validate($this->validRaw(['post_date_gmt' => '0000-00-00 00:00:00']));
    }

    public function test_null_datetime_is_accepted(): void
    {
        $this->expectNotToPerformAssertions();
        $this->validator->validate($this->validRaw(['post_date_gmt' => null]));
    }

    public function test_invalid_datetime_string_throws(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches("/post_date_gmt/");
        $this->validator->validate($this->validRaw(['post_date_gmt' => 'not-a-date']));
    }

    public function test_invalid_modified_datetime_throws(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches("/post_modified_gmt/");
        $this->validator->validate($this->validRaw(['post_modified_gmt' => 'bad-date']));
    }
}
