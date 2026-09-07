<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ValidationLocalizationTest extends TestCase
{
    public function test_validation_messages_and_field_names_are_in_indonesian(): void
    {
        $errors = Validator::make(
            [
                'bands' => [
                    ['manual_score' => 50],
                    ['manual_score' => 50],
                ],
            ],
            [
                'bands.*.manual_score' => ['distinct'],
                'email' => ['required'],
                'password' => ['required'],
            ],
        )->errors()->all();

        $this->assertSame([
            'Email wajib diisi.',
            'Kata sandi wajib diisi.',
            'Nilai pilihan predikat tidak boleh memiliki nilai yang sama.',
            'Nilai pilihan predikat tidak boleh memiliki nilai yang sama.',
        ], $errors);
    }
}
