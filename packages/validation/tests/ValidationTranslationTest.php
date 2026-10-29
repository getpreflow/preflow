<?php

declare(strict_types=1);

namespace Preflow\Validation\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\I18n\TranslationRegistry;
use Preflow\I18n\Translator;
use Preflow\Validation\Message;
use Preflow\Validation\Rules\Required;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;
use Preflow\Validation\Validator;
use Preflow\Validation\ValidatorFactory;

/**
 * Validation messages (Ruling 2): each rule gives a key of the "validation" group (scope
 * public) with its parameters. A validator without a translator answers in today's English,
 * word for word; with one, in the translator's language.
 */
final class ValidationTranslationTest extends TestCase
{
    /** @return array<string, array{array<string, list<string>>, array<string, mixed>, string, string, string}> rules, data, English, German, French */
    public static function messages(): array
    {
        return [
            'required' => [['f' => ['required']], [], 'This field is required.', 'Dieses Feld ist erforderlich.', 'Ce champ est obligatoire.'],
            'email' => [['f' => ['email']], ['f' => 'nope'], 'Must be a valid email address.', 'Muss eine gültige E-Mail-Adresse sein.', 'Doit être une adresse e-mail valide.'],
            'url' => [['f' => ['url']], ['f' => 'nope'], 'Must be a valid URL.', 'Muss eine gültige URL sein.', 'Doit être une URL valide.'],
            'numeric' => [['f' => ['numeric']], ['f' => 'x'], 'Must be a number.', 'Muss eine Zahl sein.', 'Doit être un nombre.'],
            'integer' => [['f' => ['integer']], ['f' => '1.5'], 'Must be an integer.', 'Muss eine ganze Zahl sein.', 'Doit être un nombre entier.'],
            'min chars' => [['f' => ['min:3']], ['f' => 'ab'], 'Must be at least 3 characters.', 'Muss mindestens 3 Zeichen lang sein.', 'Doit contenir au moins 3 caractères.'],
            'min number' => [['f' => ['min:3']], ['f' => 2], 'Must be at least 3.', 'Muss mindestens 3 sein.', 'Doit être supérieur ou égal à 3.'],
            'max chars' => [['f' => ['max:2']], ['f' => 'abc'], 'Must be at most 2 characters.', 'Darf höchstens 2 Zeichen lang sein.', 'Doit contenir au plus 2 caractères.'],
            'max number' => [['f' => ['max:2.5']], ['f' => 3], 'Must be at most 2.5.', 'Darf höchstens 2.5 sein.', 'Doit être inférieur ou égal à 2.5.'],
            'between' => [['f' => ['between:2,4']], ['f' => 9], 'Must be between 2 and 4.', 'Muss zwischen 2 und 4 liegen.', 'Doit être entre 2 et 4.'],
            'step' => [['f' => ['step:0.5']], ['f' => 2.3], 'Must be a multiple of 0.5.', 'Muss ein Vielfaches von 0.5 sein.', 'Doit être un multiple de 0.5.'],
            'in' => [['f' => ['in:a,b']], ['f' => 'c'], 'Must be one of: a, b.', 'Muss eines davon sein: a, b.', 'Doit être l’une de ces valeurs : a, b.'],
            'regex' => [['f' => ['regex:/^a$/']], ['f' => 'b'], 'Format is invalid.', 'Das Format ist ungültig.', 'Le format n’est pas valide.'],
            'confirmed' => [['f' => ['confirmed']], ['f' => 'a', 'f_confirmation' => 'b'], 'Confirmation does not match.', 'Die Bestätigung stimmt nicht überein.', 'La confirmation ne correspond pas.'],
        ];
    }

    private static function translator(string $locale): Translator
    {
        $translator = new Translator('/no/such/lang', $locale, 'en');
        $translator->addDefaults('validation', dirname(__DIR__) . '/lang');

        return $translator;
    }

    /** @param array<string, list<string>> $rules @param array<string, mixed> $data */
    #[DataProvider('messages')]
    public function test_without_a_translator_the_message_is_todays_english(array $rules, array $data, string $english, string $german, string $french): void
    {
        $factory = new ValidatorFactory(new RuleFactory());

        $this->assertSame([$english], $factory->make($rules, $data)->validate()->fieldErrors('f'));
    }

    /** @param array<string, list<string>> $rules @param array<string, mixed> $data */
    #[DataProvider('messages')]
    public function test_with_a_translator_the_message_is_in_its_language(array $rules, array $data, string $english, string $german, string $french): void
    {
        foreach (['en' => $english, 'de' => $german, 'fr' => $french] as $locale => $expected) {
            $factory = new ValidatorFactory(new RuleFactory(), self::translator($locale));
            $this->assertSame([$expected], $factory->make($rules, $data)->validate()->fieldErrors('f'), $locale);
            $this->assertSame([$expected], (new Validator(new RuleFactory(), $rules, $data, null, self::translator($locale)))->validate()->fieldErrors('f'), "{$locale}, Validator");
        }
    }

    public function test_a_rule_gives_a_key_and_parameters_whose_text_is_the_english_one(): void
    {
        $message = (new Required())->validate(null, new ValidationContext('f', []));

        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame('validation.required', $message->key);
        $this->assertSame('This field is required.', (string) $message);
        $min = (new RuleFactory())->resolve('min:3')->validate('ab', new ValidationContext('f', []));
        $this->assertInstanceOf(Message::class, $min);
        $this->assertSame(['min' => '3'], $min->params);
    }

    public function test_a_custom_rules_own_string_is_kept_with_a_translator(): void
    {
        $rule = new class implements ValidationRule {
            public function validate(mixed $value, ValidationContext $context): true|string
            {
                return 'Nope, said the site.';
            }
        };
        $factory = new ValidatorFactory(new RuleFactory(), self::translator('de'));

        $this->assertSame(['Nope, said the site.'], $factory->make(['f' => [$rule]], ['f' => 'x'])->validate()->fieldErrors('f'));
    }

    public function test_a_key_the_translator_lacks_falls_back_to_the_english_text(): void
    {
        $bare = new Translator('/no/such/lang', 'de', 'de'); // no validation group registered
        $factory = new ValidatorFactory(new RuleFactory(), $bare);

        $this->assertSame(['This field is required.'], $factory->make(['f' => ['required']], [])->validate()->fieldErrors('f'));
    }

    public function test_the_factorys_translator_can_be_set_for_a_while_and_put_back(): void
    {
        $factory = new ValidatorFactory(new RuleFactory());

        $this->assertNull($factory->useTranslator(self::translator('de')));
        $this->assertSame(['Dieses Feld ist erforderlich.'], $factory->make(['f' => ['required']], [])->validate()->fieldErrors('f'));
        $previous = $factory->useTranslator(null);
        $this->assertInstanceOf(Translator::class, $previous);
        $this->assertSame(['This field is required.'], $factory->make(['f' => ['required']], [])->validate()->fieldErrors('f'));
    }

    public function test_the_package_declares_the_public_group_with_the_same_keys_in_en_de_and_fr(): void
    {
        $root = dirname(__DIR__);
        $composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
        $installed = sys_get_temp_dir() . '/preflow_installed_' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($installed, (string) json_encode(['packages' => [[...$composer, 'install-path' => $root]]]));
        try {
            $registry = TranslationRegistry::fromInstalled($installed);
        } finally {
            unlink($installed);
        }
        $this->assertSame('public', $registry->scope('validation'));
        $this->assertArrayHasKey('preflow/i18n', $composer['require']);

        $translator = self::translator('en');
        $keys = array_keys($translator->entries('validation', 'en'));
        $this->assertNotEmpty($keys);
        foreach (['de', 'fr'] as $locale) {
            $this->assertEqualsCanonicalizing($keys, array_keys($translator->entries('validation', $locale)), $locale);
        }
    }
}
