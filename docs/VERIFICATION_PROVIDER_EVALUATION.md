# Extraction Provider Evaluation

## Requirement

Identity-document extraction for African markets: Zimbabwe, South Africa,
Zambia, Kenya (initial), extensible. JPEG/PNG/WebP/PDF/HEIC + camera captures.
Must never silently send identity documents to an uncontracted third party.

## Candidates evaluated

| Provider | Identity-doc support | ZW/ZA docs | Structured fields | Cost model | Data residency | Verdict |
|---|---|---|---|---|---|---|
| **Tesseract 5 (local)** | Generic OCR, no doc knowledge | Works (text) | None — our `FieldExtractor` adds MRZ/label parsing | Free | **Fully on-prem** | **Primary (v1)** |
| AWS Textract (`AnalyzeID`) | US driver's licences + passports only — **no ZW/ZA national ID** | Partial (passport MRZ only) | Yes (normalized) | ~$1.50/1k pages | Region-selectable | Adapter implemented, **disabled** — no ZW ID support |
| Azure AI Document Intelligence | `idDocument` model — passports + many national IDs incl. ZA | ZA yes, ZW not documented | Yes | ~$1.50–10/1k | Region-selectable | **Recommended phase-2 primary** |
| Google Document AI | ID proofing — passport/dL focus, country coverage varies | Partial | Yes | ~$1.50+/1k | Limited regions | Candidate, no ZW advantage over Azure |
| Regula / Microblink / Onfido-class IDV SDKs | **Purpose-built for African IDs** (500+ doc templates, authenticity, MRZ+barcode+chip) | **Best coverage** | Yes + authenticity | Commercial licence | SDK can run on-prem | **Recommended phase-3 for authenticity** |
| OCR.space / free tiers | Generic | No | No | Cheap | US-only, weak privacy | Rejected — PII to unsuitable processor |

## Decision

**v1 ships Tesseract as the default provider** because:

1. It keeps identity documents entirely on our own infrastructure — no DPA
   required, no cross-border transfer, zero per-document cost.
2. Our `FieldExtractor` layer (ICAO-9303 MRZ with check-digit validation,
   label-proximity regexes, country ID formats) compensates for the lack of
   document templates — this is where real verification value lives, and it
   is provider-agnostic.
3. The `ExtractionProvider` contract means upgrading to Azure/Regula later
   changes config, not domain code. `provider_attempts` records which
   provider produced each extraction for A/B quality analysis.

Honest limits of v1 (recorded, not hidden):

- Generic OCR misreads names ("TENDAI"→"TENDAT" observed in testing) — this
  is precisely why ambiguous fields route to `review_required`.
- No authenticity detection beyond MRZ check-digit validation and metadata
  signals. We do **not** claim document-genuineness verification.
- No barcode/QR decoding yet — adapter slot exists (`BarcodeProvider`) but is
  not implemented.

## Recommended path

- **Phase 2:** Azure AI Document Intelligence as a second provider for
  higher-quality field extraction once a DPA + volume pricing is in place
  (`EXTRACTION_PROVIDERS=azure,tesseract` — fallback stays local).
- **Phase 3:** A dedicated IDV SDK (Regula/Microblink) for authenticity
  checks (template matching, security features, barcode/QR) when the
  business requires fraud-grade assurance.
- **External identity:** no government/registry API integration exists;
  nothing in the platform claims authoritative-source verification.
