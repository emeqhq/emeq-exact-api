<?php

declare(strict_types=1);

namespace Emeq\ExactApi\Http\Request\Write;

use Emeq\ExactApi\Http\Request\BaseRequest;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Traits\Body\HasJsonBody;

/**
 * Grootboek en/of btw van een inkoop-boekingsregel wijzigen — `PUT purchaseentry/PurchaseEntryLines(guid'{id}')`.
 *
 * Key = de `ID` (GUID) van de regel. De body draagt alleen de opgegeven velden uit
 * `GLAccount` (Edm.Guid), `VATCode`, `VATAmountFC` en `VATPercentage`, zodat Exact de overige regelvelden
 * ongemoeid laat.
 */
final class UpdatePurchaseEntryLine extends BaseRequest implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::PUT;

    public function __construct(
        private readonly string $id,
        private readonly ?string $glAccount = null,
        private readonly ?string $vatCode = null,
        private readonly int|float|null $vatAmount = null,
        private readonly int|float|null $vatPercentage = null,
    ) {
    }

    public function resolveEndpoint(): string
    {
        return "/purchaseentry/PurchaseEntryLines(guid'{$this->id}')";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return array_filter([
            'GLAccount'     => $this->glAccount,
            'VATCode'       => $this->vatCode,
            'VATAmountFC'   => $this->vatAmount,
            'VATPercentage' => $this->vatPercentage,
        ], static fn (mixed $v): bool => null !== $v);
    }
}
