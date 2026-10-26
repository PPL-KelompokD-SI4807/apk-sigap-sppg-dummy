<?php

namespace App\Services\Ai\Drafts;

use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use InvalidArgumentException;

/**
 * Validasi dry-run payload draf AI memakai Form Request modul yang SAMA dengan REST API
 * (aturan validasi didefinisikan sekali). Tidak menulis apa pun ke basis data.
 *
 * Form Request dibuat sebagai permintaan sintetis milik $user (user resolver, route parameter
 * untuk update), lalu authorize() + prepareForValidation() + rules() + withValidator()/after()
 * dijalankan persis seperti saat permintaan HTTP sebenarnya.
 *
 *   $hasil = app(DraftValidator::class)->validate(StoreTitikLayananRequest::class, $payload, $user);
 *   $hasil->errors      // ['jenis' => ['...']]
 *   $hasil->confidence  // ['nama' => 0.9, 'jenis' => 0.0]
 */
class DraftValidator
{
    /**
     * @param  class-string<FormRequest>  $formRequestClass
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $routeParameters  mis. ['titikLayanan' => $model] untuk update
     * @param  array<string, mixed>  $modelConfidence  keyakinan dari model per field (0-1)
     */
    public function validate(string $formRequestClass, array $payload, User $user, array $routeParameters = [], array $modelConfidence = []): DraftValidationResult
    {
        if (! is_subclass_of($formRequestClass, FormRequest::class)) {
            throw new InvalidArgumentException("{$formRequestClass} bukan Form Request.");
        }

        $request = $this->makeRequest($formRequestClass, $payload, $user, $routeParameters);

        if (method_exists($request, 'authorize') && ! (bool) app()->call([$request, 'authorize'])) {
            return new DraftValidationResult(false);
        }

        /** @var Validator $validator */
        $validator = (fn () => $this->getValidatorInstance())->call($request);
        $errors = $validator->errors()->toArray();
        $valid = $validator->valid();

        return new DraftValidationResult(true, $errors, $this->confidence($payload, $errors, $modelConfidence), $valid);
    }

    /**
     * @param  class-string<FormRequest>  $class
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $routeParameters
     */
    private function makeRequest(string $class, array $payload, User $user, array $routeParameters): FormRequest
    {
        /** @var FormRequest $request */
        $request = $class::create('/ai-dry-run', $routeParameters === [] ? 'POST' : 'PUT', $payload);
        $request->headers->set('Accept', 'application/json');
        $request->setContainer(app())->setRedirector(app('redirect'));
        $request->setUserResolver(fn () => $user);

        $route = new Route([$request->getMethod()], 'ai-dry-run', []);
        $route->parameters = $routeParameters;
        $request->setRouteResolver(fn () => $route);

        return $request;
    }

    /**
     * Keyakinan per field tingkat atas: field bergalat = 0; field terisi = keyakinan model
     * (dibatasi 0-1) atau default config ai.drafts.default_confidence.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, list<string>>  $errors
     * @param  array<string, mixed>  $modelConfidence
     * @return array<string, float>
     */
    private function confidence(array $payload, array $errors, array $modelConfidence): array
    {
        $default = (float) config('ai.drafts.default_confidence', 0.8);
        $scores = [];

        foreach ($payload as $field => $_) {
            $given = $modelConfidence[$field] ?? null;
            $scores[(string) $field] = round(is_int($given) || is_float($given) ? max(0.0, min(1.0, (float) $given)) : $default, 2);
        }

        foreach (array_keys($errors) as $key) {
            $scores[explode('.', (string) $key)[0]] = 0.0;
        }

        return $scores;
    }
}
