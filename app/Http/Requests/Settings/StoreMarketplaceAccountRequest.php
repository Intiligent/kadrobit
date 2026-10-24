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
            'contributor_id' => [Rule::requiredIf(fn () => $this->loginIsContributorId()), 'nullable', 'string', 'max:64'],
            'host' => [Rule::requiredIf(fn () => ! $this->defaultHost()), 'nullable', 'string', 'max:255', new PublicHostRule],
            'login' => [Rule::requiredIf(fn () => ! $this->loginIsContributorId()), 'nullable', 'string', 'max:190'],
            'password' => ['required', 'string', 'max:190'],
        ];
    }

    public function marketplace(): ?Marketplace
    {
        return $this->marketplace ??= Marketplace::query()->where('code', $this->input('marketplace'))->first();
    }

    /**
     * What the connection check and the account need: the marketplace default server unless changed,
     * and the contributor ID as the login where the marketplace uses it so.
     *
     * @return array{host: string, port: int|null, login: string, password: string}
     */
    public function connection(): array
    {
        return [
            'host' => $this->input('host') ?: $this->defaultHost(),
            'port' => $this->marketplace()->upload_defaults['port'] ?? null,
            'login' => $this->input($this->loginIsContributorId() ? 'contributor_id' : 'login'),
            'password' => $this->input('password'),
        ];
    }

    protected function loginIsContributorId(): bool
    {
        return (bool) $this->marketplace()?->loginIsContributorId();
    }

    protected function defaultHost(): ?string
    {
        return $this->marketplace()?->upload_defaults['host'] ?? null;
    }
}
