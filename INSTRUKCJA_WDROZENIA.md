# AxiTrace Magento — instrukcja wdrożenia (dla Ciebie)

Krótko: **kod jest gotowy, wersja 0.1.0 wycięta, ZIP-y zbudowane i podpięte pod stronę.**
Adobe Marketplace **NIE jest obowiązkowy** — merchant instaluje wtyczkę przez
Composer (Packagist) albo pobierając ZIP z axitrace.com. Marketplace to tylko
opcjonalna „witryna" dla discovery (do zrobienia później, wymaga loga + screenów +
2–6 tyg. review). Poniżej tylko to, czego **ja nie mogę zrobić za Ciebie**.

---

## ✅ Co już zrobione (przeze mnie — nic nie musisz tu robić)

- `CHANGELOG.md` (main + Hyvä) — przeniesione z `[Unreleased]` na **0.1.0 (2026-05-22)**.
- ZIP-y zbudowane i wrzucone do `landing-page/public/downloads/`:
  - `axitrace-magento-plugin-latest.zip` (moduł główny, Luma)
  - `axitrace-magento-plugin-hyva-latest.zip` (Hyvä — wcześniej link był martwy, teraz działa)
- Strona docs (`/docs/integrations/magento`) i panel admina linkują do obu ZIP-ów — zweryfikowane, że pliki istnieją.
- README poprawione (usunięte fałszywe „listing in EQP review").

---

## 🔧 Co musisz zrobić Ty

### KROK 0 — Deploy (żeby ZIP-y były dostępne pod axitrace.com)

To zwykły deploy strony. Powiedz mi „deploy" albo odpal sam:

```bash
./event-worker/deploy.sh
```

Po tym oba linki działają i merchant może już instalować wtyczkę przez ZIP.
**To wystarczy, żeby Magento było używalne.** Kroki 1–2 poniżej dodają wygodny
`composer require` (Packagist) i są mocno zalecane, ale nie blokują startu.

---

### KROK 1 — Publiczne repozytoria GitHub (wymagane do Packagist)

Composer/Packagist ciągnie kod z publicznego repo. Potrzebne **dwa** repo w org `axitrace`
(nazwy muszą zgadzać się z tym, co jest w `composer.json` → `support.issues`):

- `axitrace/axitrace-magento-plugin`        ← folder `magento-plugin/`
- `axitrace/axitrace-magento-plugin-hyva`   ← folder `magento-hyva-plugin/`

Najszybciej przez `gh` (musisz być zalogowany: `gh auth login`):

```bash
# --- moduł główny ---
cd magento-plugin
git init -b main
git add -A
git commit -m "AxiTrace Magento module 0.1.0"
gh repo create axitrace/axitrace-magento-plugin --public --source=. --remote=origin --push
git tag v0.1.0 && git push origin v0.1.0
cd ..

# --- moduł Hyvä ---
cd magento-hyva-plugin
git init -b main
git add -A
git commit -m "AxiTrace Magento Hyva module 0.1.0"
gh repo create axitrace/axitrace-magento-plugin-hyva --public --source=. --remote=origin --push
git tag v0.1.0 && git push origin v0.1.0
cd ..
```

> Tag `v0.1.0` jest istotny — Packagist bierze numer wersji z tagów git, nie z `composer.json`.

---

### KROK 2 — Publikacja na Packagist (wymaga Twojego loginu)

1. Wejdź na https://packagist.org → zaloguj się przez GitHub.
2. **Submit** → wklej `https://github.com/axitrace/axitrace-magento-plugin` → Submit.
3. **Submit** → wklej `https://github.com/axitrace/axitrace-magento-plugin-hyva` → Submit.
4. Na każdym pakiecie kliknij **Settings → włącz auto-update** (GitHub webhook),
   żeby kolejne tagi same się zaciągały. Packagist zwykle proponuje to automatycznie po pierwszym submit.

Po tym kroku działa:
```bash
composer require axitrace/module-tracking
composer require axitrace/module-tracking-hyva   # tylko sklepy na Hyvä
```

---

### KROK 3 (opcjonalny, później) — Adobe Commerce Marketplace

Tylko jeśli chcesz dodatkowe discovery + plakietkę „EQP". **Nie jest potrzebny do
sprzedaży/instalacji.** Wymaga ode mnie nic więcej poza tym, co już jest, a od Ciebie:

- konto sprzedawcy na https://developer.adobe.com/commerce/marketplace/ (dane firmy),
- **logo** modułu (200×200 px PNG) — branding, tego nie wygeneruję,
- 2–6 tygodni kolejki review (EQP).

Screenshoty (admin config, status, instalacja) — **te mogę wygenerować ja**, ale
wymagają postawienia działającej instancji Magento (jest gotowy harness w
`magento-plugin/tests/e2e/`, `make e2e`). Daj znać, jak będziesz chciał ruszać
ścieżkę Adobe — wtedy odpalę E2E i zrobię zrzuty. Brief assetów: `marketplace/README.md`.

---

## TL;DR

| Krok | Kto | Status |
|------|-----|--------|
| Kod + wersja 0.1.0 + ZIP-y + linki + docs | ja | ✅ zrobione |
| Deploy strony (ZIP-y live) | Ty: „deploy" | ⬜ |
| Publiczne repo GitHub + tag v0.1.0 (×2) | Ty (credentials) | ⬜ |
| Submit na Packagist (×2) | Ty (login) | ⬜ |
| Adobe Marketplace (logo + review) | Ty + ja (screeny) | ⬜ opcjonalne, później |

Po **Deploy + GitHub + Packagist** Magento jest w pełni używalne dla merchantów —
tak samo jak WooCommerce, bez czekania na Adobe.
