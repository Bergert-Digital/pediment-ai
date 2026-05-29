# Privacy / GDPR disclosure

When this plugin runs, the following data is sent to Anthropic:

- The editor's prompt text (Compose / Edit / Refine).
- Brand Settings values: brand name, tagline, voice/tone.
- For Edit and Refine: the current block tree (including all editorial copy) of the page being edited.
- For Compose / Edit: URLs the model decides to fetch via `web_fetch`. Anthropic fetches these from its own infrastructure.

No customer data, contact-form submissions, user accounts, or commerce data are sent.

## Recommended privacy-policy paragraph (German clients)

> Unsere Website nutzt zur Inhalts­erstellung im Backend einen KI-Dienst der Anthropic, PBC (548 Market St, San Francisco, CA 94104, USA). Bei der Nutzung der Funktion werden die Eingabe des Redakteurs sowie Marken­einstellungen unserer Website an Anthropic übertragen. Eine Verarbeitung erfolgt auf Grundlage unseres berechtigten Interesses gemäß Art. 6 Abs. 1 lit. f DSGVO. Datenübermittlung in die USA erfolgt auf Basis der EU-Standard­vertrags­klauseln.

## Recommended privacy-policy paragraph (English)

> Our website uses an AI service from Anthropic, PBC (548 Market St, San Francisco, CA 94104, USA) for internal content drafting. When this feature is used, the editor's prompt and our brand settings are transmitted to Anthropic. Processing is based on our legitimate interest under Art. 6(1)(f) GDPR. Transfers to the US rely on the EU Standard Contractual Clauses.

## Disable

Toggle "Mock mode" on in Settings → Pediment AI, or unset `ANTHROPIC_API_KEY` and clear the key in settings. The plugin's UI remains visible but cannot reach Anthropic.
