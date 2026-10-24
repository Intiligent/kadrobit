<?php

namespace App\Http\Requests\Settings;

use App\Enums\UploadProtocol;
use App\Models\Marketplace;
use App\Rules\PublicHostRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMarketplaceAccountRequest extends FormRequest
{
    protected ?Marketplace $marketplace = null;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'marketplace' => [
                'required',
                'string',
                Rule::exists('marketplaces', 'code')
                    ->where('is_active', true)
                    ->whereIn('upload_protocol', array_column(UploadProtocol::fileTransfer(), 'value')),
            ],
            'label' => [
                'required',
                'string',
                'max:60',
                Rule::unique('marketplace_accounts', 'label')
                    ->where('user_id', $this->user()->id)
                    ->where('marketplace_id', $this->marketplace()?->id ?? 0),
            ],
            'contributor_id' => ['nullable', 'string', 'max:64'],
            'host' => [Rule::requiredIf(fn () => ! $this->defaultHost()), 'nullable', 'string', 'max:255', new PublicHostRule],
            'login' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string', 'max:190'],
        ];
    }

    public function marketplace(): ?Marketplace
    {
        return $this->marketplace ??= Marketplace::query()->where('code', $this->input('marketplace'))->first();
    }

    /**
     * What the connection check and the account need: the marketplace default server unless changed.
     *
     * @return array{host: string, port: int|null, login: string, password: string}
     */
    public function connection(): array
    {
        return [
            'host' => $this->input('host') ?: $this->defaultHost(),
            'port' => $this->marketplace()->upload_defaults['port'] ?? null,
            'login' => $this->input('login'),
            'password' => $this->input('password'),
        ];
    }

    protected function defaultHost(): ?string
    {
        return $this->marketplace()?->upload_defaults['host'] ?? null;
    }

    protected function prepareForValidation(): void
    {
        // The password is taken as typed: spaces may be part of it.
        $this->merge(collect(['label', 'contributor_id', 'host', 'login'])
            ->filter(fn (string $field) => is_string($this->input($field)))
            ->mapWithKeys(fn (string $field) => [$field => trim($this->input($field))])
            ->all());
    }
}
