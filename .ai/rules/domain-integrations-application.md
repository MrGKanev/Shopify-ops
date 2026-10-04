---
paths:
  - 'app/{Domain,Integrations,Application}/**'
---

# Domain Integrations Application

## Validate phones and addresses with the shared domain helpers
Phone validation and E.164 formatting use App\Domain\Orders\PhoneNumberValidator (libphonenumber); country address rules (required postal code / administrative area, postal code format) use App\Domain\Orders\AddressFormatRules (commerceguys/addressing). Shipping address problems come from AddressCheckAnalyzer::check(). Do not add per-country regexes or hard-coded US/CA rules. Only pass ISO 3166-1 alpha-2 codes; Shopify's full country name is not a code.
