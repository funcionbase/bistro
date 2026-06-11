<?php

declare(strict_types=1);

namespace App\Services\Dian;

use App\Services\Dian\DTOs\DocumentDto;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Str;

/**
 * Generador de XML UBL 2.1 Colombia para documentos DIAN.
 *
 * Hoy produce estructura válida según el Anexo Técnico DIAN (raíz
 * `<Invoice>`/`<CreditNote>`/`<DebitNote>` con namespaces canónicos,
 * `<cbc:UUID schemeID="2" schemeName="CUFE-SHA384">`, `<cac:AccountingSupplierParty>`,
 * `<cac:AccountingCustomerParty>`, `<cac:LegalMonetaryTotal>`, `<cac:InvoiceLine>`,
 * y bloque `<sts:DianExtensions>` placeholder).
 *
 * NO incluye firma XAdES criptográfica real — eso lo aporta el provider
 * real cuando se contrate. El bloque `<ds:Signature>` se renderiza con
 * placeholders para que el XML pase validación de estructura sin pretender
 * firma legalmente válida (consistente con `MockDianProvider`).
 */
class DianXmlBuilder
{
    private const NS = [
        'invoice' => 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2',
        'creditnote' => 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2',
        'debitnote' => 'urn:oasis:names:specification:ubl:schema:xsd:DebitNote-2',
        'cac' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
        'cbc' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
        'ext' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2',
        'sts' => 'dian:gov:co:facturaelectronica:Structures-2-1',
        'ds' => 'http://www.w3.org/2000/09/xmldsig#',
        'xades' => 'http://uri.etsi.org/01903/v1.3.2#',
    ];

    public function build(DocumentDto $dto, string $cufeOrCude): string
    {
        $rootTag = match ($dto->documentType) {
            'credit_note', 'pos_equivalent_credit_note' => 'CreditNote',
            'debit_note' => 'DebitNote',
            default => 'Invoice',
        };
        $rootNs = self::NS[strtolower($rootTag)] ?? self::NS['invoice'];

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $root = $doc->createElementNS($rootNs, $rootTag);
        $root->setAttribute('xmlns:cac', self::NS['cac']);
        $root->setAttribute('xmlns:cbc', self::NS['cbc']);
        $root->setAttribute('xmlns:ext', self::NS['ext']);
        $root->setAttribute('xmlns:sts', self::NS['sts']);
        $root->setAttribute('xmlns:ds', self::NS['ds']);
        $root->setAttribute('xmlns:xades', self::NS['xades']);
        $doc->appendChild($root);

        $this->appendExtensions($doc, $root, $dto, $cufeOrCude);

        $this->cbc($doc, $root, 'UBLVersionID', '2.1');
        $this->cbc($doc, $root, 'CustomizationID', '10');
        $this->cbc($doc, $root, 'ProfileID', $dto->environment === 'produccion' ? 'DIAN 2.1' : 'DIAN 2.1: Habilitación');
        $this->cbc($doc, $root, 'ProfileExecutionID', (string) config("dian.environment_codes.{$dto->environment}", '2'));
        $this->cbc($doc, $root, 'ID', $dto->fullNumber);

        $uuid = $doc->createElementNS(self::NS['cbc'], 'cbc:UUID', $cufeOrCude);
        $uuid->setAttribute('schemeID', $dto->environment === 'produccion' ? '1' : '2');
        $uuid->setAttribute('schemeName', strtoupper($dto->unique_code_type).'-SHA384');
        $root->appendChild($uuid);

        $this->cbc($doc, $root, 'IssueDate', $dto->issuedAt->format('Y-m-d'));
        $this->cbc($doc, $root, 'IssueTime', $dto->issuedAt->format('H:i:s').'-05:00');
        $this->cbc($doc, $root, 'InvoiceTypeCode', (string) config("dian.document_types.{$dto->documentType}", '01'));
        $this->cbc($doc, $root, 'DocumentCurrencyCode', $dto->currency);
        $this->cbc($doc, $root, 'LineCountNumeric', (string) count($dto->lines));

        if ($dto->references !== null) {
            $this->appendBillingReference($doc, $root, $dto);
        }

        $this->appendParty($doc, $root, 'AccountingSupplierParty', [
            'doc_number' => $dto->issuerNit,
            'dv' => $dto->issuerDv,
            'doc_type' => 'NIT',
            'legal_name' => $dto->issuerLegalName,
            'commercial_name' => $dto->issuerCommercialName,
            'address' => $dto->issuerAddress,
            'municipality' => $dto->issuerMunicipalityCode,
            'email' => $dto->issuerEmail,
            'phone' => $dto->issuerPhone,
            'fiscal_responsibilities' => $dto->issuerFiscalResponsibilities,
        ]);

        $this->appendParty($doc, $root, 'AccountingCustomerParty', [
            'doc_number' => $dto->recipient->docNumber,
            'dv' => $dto->recipient->dv,
            'doc_type' => $dto->recipient->docType,
            'legal_name' => $dto->recipient->legalName,
            'commercial_name' => $dto->recipient->legalName,
            'address' => $dto->recipient->address,
            'municipality' => $dto->recipient->municipalityCode,
            'email' => $dto->recipient->email,
            'phone' => null,
            'fiscal_responsibilities' => $dto->recipient->fiscalResponsibilities,
        ]);

        $this->appendTaxTotal($doc, $root, $dto);
        $this->appendLegalMonetaryTotal($doc, $root, $dto);

        foreach ($dto->lines as $index => $line) {
            $this->appendInvoiceLine($doc, $root, $line, $index + 1, $dto->currency);
        }

        return $doc->saveXML() ?: '';
    }

    private function appendExtensions(DOMDocument $doc, DOMElement $root, DocumentDto $dto, string $cufeOrCude): void
    {
        $extensions = $doc->createElementNS(self::NS['ext'], 'ext:UBLExtensions');
        $root->appendChild($extensions);

        // 1) DianExtensions
        $dianExt = $doc->createElementNS(self::NS['ext'], 'ext:UBLExtension');
        $extContent = $doc->createElementNS(self::NS['ext'], 'ext:ExtensionContent');
        $dianExt->appendChild($extContent);
        $extensions->appendChild($dianExt);

        $dianStructures = $doc->createElementNS(self::NS['sts'], 'sts:DianExtensions');
        $extContent->appendChild($dianStructures);

        $invoiceControl = $doc->createElementNS(self::NS['sts'], 'sts:InvoiceControl');
        $this->stsChild($doc, $invoiceControl, 'InvoiceAuthorization', $dto->resolutionNumber);
        $period = $doc->createElementNS(self::NS['sts'], 'sts:AuthorizationPeriod');
        $this->cbcChild($doc, $period, 'StartDate', $dto->issuedAt->format('Y-m-d'));
        $this->cbcChild($doc, $period, 'EndDate', $dto->issuedAt->modify('+1 year')->format('Y-m-d'));
        $invoiceControl->appendChild($period);
        $authInvoices = $doc->createElementNS(self::NS['sts'], 'sts:AuthorizedInvoices');
        $this->cbcChild($doc, $authInvoices, 'Prefix', $dto->prefix);
        $this->cbcChild($doc, $authInvoices, 'From', $dto->resolutionRangeFrom);
        $this->cbcChild($doc, $authInvoices, 'To', $dto->resolutionRangeTo);
        $invoiceControl->appendChild($authInvoices);
        $dianStructures->appendChild($invoiceControl);

        $invoiceSource = $doc->createElementNS(self::NS['sts'], 'sts:InvoiceSource');
        $identCode = $doc->createElementNS(self::NS['cbc'], 'cbc:IdentificationCode', 'CO');
        $identCode->setAttribute('listAgencyID', '6');
        $identCode->setAttribute('listAgencyName', 'United Nations Economic Commission for Europe');
        $identCode->setAttribute('listSchemeURI', 'urn:oasis:names:specification:ubl:codelist:gc:CountryIdentificationCode-2.1');
        $invoiceSource->appendChild($identCode);
        $dianStructures->appendChild($invoiceSource);

        $softwareProvider = $doc->createElementNS(self::NS['sts'], 'sts:SoftwareProvider');
        $this->stsChild($doc, $softwareProvider, 'ProviderID', $dto->issuerNit, ['schemeID' => $dto->issuerDv ?? '0']);
        $this->stsChild($doc, $softwareProvider, 'SoftwareID', (string) Str::uuid());
        $dianStructures->appendChild($softwareProvider);

        $this->stsChild($doc, $dianStructures, 'SoftwareSecurityCode', hash('sha384', $cufeOrCude.$dto->fullNumber));
        $this->stsChild($doc, $dianStructures, 'AuthorizationProvider');

        $qr = $doc->createElementNS(self::NS['sts'], 'sts:QRCode', $this->qrText($dto, $cufeOrCude));
        $dianStructures->appendChild($qr);

        // 2) Signature placeholder
        $sigExt = $doc->createElementNS(self::NS['ext'], 'ext:UBLExtension');
        $sigContent = $doc->createElementNS(self::NS['ext'], 'ext:ExtensionContent');
        $sigExt->appendChild($sigContent);
        $extensions->appendChild($sigExt);

        $signature = $doc->createElementNS(self::NS['ds'], 'ds:Signature');
        $signature->setAttribute('Id', 'placeholder-signature');
        $signedInfo = $doc->createElementNS(self::NS['ds'], 'ds:SignedInfo');
        $signature->appendChild($signedInfo);
        $signatureValue = $doc->createElementNS(self::NS['ds'], 'ds:SignatureValue', 'MOCK-NOT-A-REAL-XADES-SIGNATURE');
        $signature->appendChild($signatureValue);
        $sigContent->appendChild($signature);
    }

    private function qrText(DocumentDto $dto, string $cufeOrCude): string
    {
        $url = (config("dian.qr_base_url.{$dto->environment}") ?? '').$cufeOrCude;

        return implode("\n", [
            'NumFac: '.$dto->fullNumber,
            'FecFac: '.$dto->issuedAt->format('Y-m-d H:i:s'),
            'NitFac: '.$dto->issuerNit,
            'DocAdq: '.$dto->recipient->docNumber,
            'ValFac: '.number_format($dto->total, 2, '.', ''),
            'ValIva: '.number_format($dto->ivaAmount, 2, '.', ''),
            'ValOtroIm: '.number_format($dto->incAmount + $dto->icaAmount, 2, '.', ''),
            'ValTolFac: '.number_format($dto->total, 2, '.', ''),
            strtoupper($dto->unique_code_type).': '.$cufeOrCude,
            'QRCode: '.$url,
        ]);
    }

    private function appendBillingReference(DOMDocument $doc, DOMElement $root, DocumentDto $dto): void
    {
        $billingRef = $doc->createElementNS(self::NS['cac'], 'cac:BillingReference');
        $invoiceDocRef = $doc->createElementNS(self::NS['cac'], 'cac:InvoiceDocumentReference');
        $this->cbcChild($doc, $invoiceDocRef, 'ID', $dto->references->fullNumber);
        $uuid = $doc->createElementNS(self::NS['cbc'], 'cbc:UUID', $dto->references->uniqueCode);
        $uuid->setAttribute('schemeName', strtoupper($dto->references->uniqueCodeType).'-SHA384');
        $invoiceDocRef->appendChild($uuid);
        $this->cbcChild($doc, $invoiceDocRef, 'IssueDate', $dto->references->issuedAt->format('Y-m-d'));
        $billingRef->appendChild($invoiceDocRef);
        $root->appendChild($billingRef);
    }

    /**
     * @param  array{
     *   doc_number: string, dv: ?string, doc_type: string,
     *   legal_name: string, commercial_name: string,
     *   address: ?string, municipality: ?string,
     *   email: ?string, phone: ?string,
     *   fiscal_responsibilities: list<string>,
     * }  $party
     */
    private function appendParty(DOMDocument $doc, DOMElement $root, string $partyTag, array $party): void
    {
        $partyRoot = $doc->createElementNS(self::NS['cac'], "cac:{$partyTag}");
        $aggregate = $doc->createElementNS(self::NS['cac'], 'cac:Party');

        $partyName = $doc->createElementNS(self::NS['cac'], 'cac:PartyName');
        $this->cbcChild($doc, $partyName, 'Name', $party['commercial_name']);
        $aggregate->appendChild($partyName);

        $partyId = $doc->createElementNS(self::NS['cac'], 'cac:PartyIdentification');
        $idEl = $doc->createElementNS(self::NS['cbc'], 'cbc:ID', $party['doc_number']);
        $idEl->setAttribute('schemeID', $party['dv'] ?? '0');
        $idEl->setAttribute('schemeName', $party['doc_type']);
        $partyId->appendChild($idEl);
        $aggregate->appendChild($partyId);

        if (filled($party['address'])) {
            $physical = $doc->createElementNS(self::NS['cac'], 'cac:PhysicalLocation');
            $address = $doc->createElementNS(self::NS['cac'], 'cac:Address');
            $this->cbcChild($doc, $address, 'ID', $party['municipality'] ?? '');
            $this->cbcChild($doc, $address, 'CityName', '');
            $this->cbcChild($doc, $address, 'CountrySubentityCode', '');
            $addressLine = $doc->createElementNS(self::NS['cac'], 'cac:AddressLine');
            $this->cbcChild($doc, $addressLine, 'Line', $party['address']);
            $address->appendChild($addressLine);
            $country = $doc->createElementNS(self::NS['cac'], 'cac:Country');
            $this->cbcChild($doc, $country, 'IdentificationCode', 'CO');
            $address->appendChild($country);
            $physical->appendChild($address);
            $aggregate->appendChild($physical);
        }

        $taxScheme = $doc->createElementNS(self::NS['cac'], 'cac:PartyTaxScheme');
        $this->cbcChild($doc, $taxScheme, 'RegistrationName', $party['legal_name']);
        $companyId = $doc->createElementNS(self::NS['cbc'], 'cbc:CompanyID', $party['doc_number']);
        $companyId->setAttribute('schemeID', $party['dv'] ?? '0');
        $companyId->setAttribute('schemeName', $party['doc_type']);
        $taxScheme->appendChild($companyId);
        $this->cbcChild(
            $doc,
            $taxScheme,
            'TaxLevelCode',
            implode(';', $party['fiscal_responsibilities'] ?: ['R-99-PN'])
        );
        $aggregate->appendChild($taxScheme);

        $legalEntity = $doc->createElementNS(self::NS['cac'], 'cac:PartyLegalEntity');
        $this->cbcChild($doc, $legalEntity, 'RegistrationName', $party['legal_name']);
        $companyIdLe = $doc->createElementNS(self::NS['cbc'], 'cbc:CompanyID', $party['doc_number']);
        $companyIdLe->setAttribute('schemeID', $party['dv'] ?? '0');
        $companyIdLe->setAttribute('schemeName', $party['doc_type']);
        $legalEntity->appendChild($companyIdLe);
        $aggregate->appendChild($legalEntity);

        if (filled($party['phone']) || filled($party['email'])) {
            $contact = $doc->createElementNS(self::NS['cac'], 'cac:Contact');
            if (filled($party['phone'])) {
                $this->cbcChild($doc, $contact, 'Telephone', (string) $party['phone']);
            }
            if (filled($party['email'])) {
                $this->cbcChild($doc, $contact, 'ElectronicMail', (string) $party['email']);
            }
            $aggregate->appendChild($contact);
        }

        $partyRoot->appendChild($aggregate);
        $root->appendChild($partyRoot);
    }

    private function appendTaxTotal(DOMDocument $doc, DOMElement $root, DocumentDto $dto): void
    {
        $totalTaxes = $dto->ivaAmount + $dto->incAmount + $dto->icaAmount;
        if ($totalTaxes <= 0) {
            return;
        }

        $taxTotal = $doc->createElementNS(self::NS['cac'], 'cac:TaxTotal');
        $taxAmount = $doc->createElementNS(self::NS['cbc'], 'cbc:TaxAmount', $this->money($totalTaxes));
        $taxAmount->setAttribute('currencyID', $dto->currency);
        $taxTotal->appendChild($taxAmount);
        $root->appendChild($taxTotal);
    }

    private function appendLegalMonetaryTotal(DOMDocument $doc, DOMElement $root, DocumentDto $dto): void
    {
        $totals = $doc->createElementNS(self::NS['cac'], 'cac:LegalMonetaryTotal');

        $line = $doc->createElementNS(self::NS['cbc'], 'cbc:LineExtensionAmount', $this->money($dto->subtotal));
        $line->setAttribute('currencyID', $dto->currency);
        $totals->appendChild($line);

        $taxExclusive = $doc->createElementNS(self::NS['cbc'], 'cbc:TaxExclusiveAmount', $this->money($dto->taxableBase));
        $taxExclusive->setAttribute('currencyID', $dto->currency);
        $totals->appendChild($taxExclusive);

        $taxInclusive = $doc->createElementNS(self::NS['cbc'], 'cbc:TaxInclusiveAmount', $this->money($dto->total));
        $taxInclusive->setAttribute('currencyID', $dto->currency);
        $totals->appendChild($taxInclusive);

        if ($dto->discountAmount > 0) {
            $discount = $doc->createElementNS(self::NS['cbc'], 'cbc:AllowanceTotalAmount', $this->money($dto->discountAmount));
            $discount->setAttribute('currencyID', $dto->currency);
            $totals->appendChild($discount);
        }

        $payable = $doc->createElementNS(self::NS['cbc'], 'cbc:PayableAmount', $this->money($dto->total));
        $payable->setAttribute('currencyID', $dto->currency);
        $totals->appendChild($payable);

        $root->appendChild($totals);
    }

    private function appendInvoiceLine(DOMDocument $doc, DOMElement $root, $line, int $index, string $currency): void
    {
        $lineEl = $doc->createElementNS(self::NS['cac'], 'cac:InvoiceLine');
        $this->cbcChild($doc, $lineEl, 'ID', (string) $index);
        $qty = $doc->createElementNS(self::NS['cbc'], 'cbc:InvoicedQuantity', (string) $line->quantity);
        $qty->setAttribute('unitCode', $line->unit);
        $lineEl->appendChild($qty);

        $lineExt = $doc->createElementNS(self::NS['cbc'], 'cbc:LineExtensionAmount', $this->money($line->lineSubtotal));
        $lineExt->setAttribute('currencyID', $currency);
        $lineEl->appendChild($lineExt);

        if ($line->taxAmount > 0) {
            $taxTotal = $doc->createElementNS(self::NS['cac'], 'cac:TaxTotal');
            $taxAmount = $doc->createElementNS(self::NS['cbc'], 'cbc:TaxAmount', $this->money($line->taxAmount));
            $taxAmount->setAttribute('currencyID', $currency);
            $taxTotal->appendChild($taxAmount);

            $subTotal = $doc->createElementNS(self::NS['cac'], 'cac:TaxSubtotal');
            $taxable = $doc->createElementNS(self::NS['cbc'], 'cbc:TaxableAmount', $this->money($line->taxableBase));
            $taxable->setAttribute('currencyID', $currency);
            $subTotal->appendChild($taxable);
            $taxAmount2 = $doc->createElementNS(self::NS['cbc'], 'cbc:TaxAmount', $this->money($line->taxAmount));
            $taxAmount2->setAttribute('currencyID', $currency);
            $subTotal->appendChild($taxAmount2);

            $taxCategory = $doc->createElementNS(self::NS['cac'], 'cac:TaxCategory');
            $this->cbcChild($doc, $taxCategory, 'Percent', number_format($line->taxRate, 2, '.', ''));
            $taxScheme = $doc->createElementNS(self::NS['cac'], 'cac:TaxScheme');
            $this->cbcChild($doc, $taxScheme, 'ID', $line->taxCode);
            $this->cbcChild($doc, $taxScheme, 'Name', match ($line->taxCode) {
                '01' => 'IVA',
                '04' => 'INC',
                '03' => 'ICA',
                default => 'No aplica',
            });
            $taxCategory->appendChild($taxScheme);
            $subTotal->appendChild($taxCategory);
            $taxTotal->appendChild($subTotal);
            $lineEl->appendChild($taxTotal);
        }

        $item = $doc->createElementNS(self::NS['cac'], 'cac:Item');
        $this->cbcChild($doc, $item, 'Description', $line->name);
        $lineEl->appendChild($item);

        $price = $doc->createElementNS(self::NS['cac'], 'cac:Price');
        $priceAmount = $doc->createElementNS(self::NS['cbc'], 'cbc:PriceAmount', $this->money($line->unitPrice));
        $priceAmount->setAttribute('currencyID', $currency);
        $price->appendChild($priceAmount);
        $baseQty = $doc->createElementNS(self::NS['cbc'], 'cbc:BaseQuantity', '1');
        $baseQty->setAttribute('unitCode', $line->unit);
        $price->appendChild($baseQty);
        $lineEl->appendChild($price);

        $root->appendChild($lineEl);
    }

    private function cbc(DOMDocument $doc, DOMElement $parent, string $tag, string $value): void
    {
        $parent->appendChild($doc->createElementNS(self::NS['cbc'], "cbc:{$tag}", $value));
    }

    private function cbcChild(DOMDocument $doc, DOMElement $parent, string $tag, string $value): void
    {
        $parent->appendChild($doc->createElementNS(self::NS['cbc'], "cbc:{$tag}", $value));
    }

    /**
     * @param  array<string,string>  $attrs
     */
    private function stsChild(DOMDocument $doc, DOMElement $parent, string $tag, string $value = '', array $attrs = []): void
    {
        $el = $doc->createElementNS(self::NS['sts'], "sts:{$tag}", $value);
        foreach ($attrs as $name => $val) {
            $el->setAttribute($name, $val);
        }
        $parent->appendChild($el);
    }

    private function money(float|int $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
