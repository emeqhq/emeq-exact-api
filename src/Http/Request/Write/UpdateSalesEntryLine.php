<?php

declare(strict_types=1);

namespace Emeq\ExactApi\Http\Request\Write;

use Emeq\ExactApi\Http\Request\BaseRequest;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Traits\Body\HasJsonBody;

/**
 * Grootboek en/of btw van een verkoop-boekingsregel wijzigen — `PUT salesentry/SalesEntryLines(guid'{id}')`.
 *
 * Key = de `ID` (GUID) van de regel. De body draagt alleen de opgegeven velden uit
 * `GLAccount` (Edm.Guid), `VATCode` en `VATAmountFC`, zodat Exact de overige regelvelden
 * ongemoeid laat.
 */
final class UpdateSalesEntryLine extends BaseRequest implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::PUT;

    public function __construct(
        private readonly string $id,
        private readonly ?string $glAccount = null,
        private readonly ?string $vatCode = null,
        private readonly int|float|null $vatAmount = null,
    ) {
    }

    public function resolveEndpoint(): string
    {
        return "/salesentry/SalesEntryLines(guid'{$this->id}')";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return array_filter([
            'GLAccount'   => $this->glAccount,
            'VATCode'     => $this->vatCode,
            'VATAmountFC' => $this->vatAmount,
        ], static fn (mixed $v): bool => null !== $v);
    }
}
