<?php

namespace App\Http\Requests\Settings;

use App\Enums\MarketplaceAccountStatus;
use App\Models\MarketplaceAccount;
use App\Rules\PublicHostRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Partial update. Changing the server, login or password signs in again before saving;
 * the name, contributor ID and on/off switch do not.
 */
class UpdateMarketplaceAccountRequest extends FormRequest
{
    protected ?MarketplaceAccount $account = null;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $account = $this->account();

        return [
            'label' => [
                'sometimes',
                'required',
                'string',
                'max:60',
                Rule::unique('marketplace_accounts', 'label')
                    ->where('user_id', $account->user_id)
                    ->where('marketplace_id', $account->marketplace_id)
                    ->ignore($account->id),
            ],
            'contributor_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'host' => ['sometimes', 'required', 'string', 'max:255', new PublicHostRule],
            'login' => ['sometimes', 'required', 'string', 'max:190'],
            'password' => ['sometimes', 'required', 'string', 'max:190'],
            // invalid_credentials is set by checks only.
            'status' => ['sometimes', Rule::in([MarketplaceAccountStatus::Active->value, MarketplaceAccountStatus::Disabled->value])],
        ];
    }

    /**
     * The account, among the user's own: someone else's gives 404, not 403.
     */
    public function account(): MarketplaceAccount
    {
        return $this->account ??= $this->user()->marketplaceAccounts()->with('marketplace')->findOrFail($this->route('account'));
    }

    public function changesConnection(): bool
    {
        return $this->hasAny(['host', 'login', 'password']);
    }

    /**
     * The saved connection with the sent changes; the saved password unless a new one is sent.
     *
     * @return array{host: string, port: int|null, login: string, password: string}
     */
    public function connection(): array
    {
        $account = $this->account();

        return [
            'host' => $this->input('host', $account->host),
            'port' => $account->port,
            'login' => $this->input('login', $account->login),
            'password' => $this->input('password', $account->credentials['password'] ?? ''),
        ];
    }
}
