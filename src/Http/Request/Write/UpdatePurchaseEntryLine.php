<?php

declare(strict_types=1);

namespace Emeq\ExactApi\Http\Request\Write;

use Emeq\ExactApi\Http\Request\BaseRequest;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Traits\Body\HasJsonBody;

/**
 * Grootboek van een inkoop-boekingsregel wijzigen — `PUT purchaseentry/PurchaseEntryLines(guid'{id}')`.
 *
 * Key = de `ID` (GUID) van de regel. De body draagt alleen `GLAccount` (Edm.Guid),
 * zodat Exact de overige regelvelden ongemoeid laat.
 */
final class UpdatePurchaseEntryLine extends BaseRequest implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::PUT;

    public function __construct(
        private readonly string $id,
        private readonly string $glAccount,
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
        return ['GLAccount' => $this->glAccount];
    }
}
