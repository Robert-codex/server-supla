# Server SUPLA

Monorepo zbierające kompletny zestaw plików do prywatnego wdrożenia SUPLA z własnym brokerem MQTT.

Repo zawiera trzy warstwy:

- `supla-core/` - kod źródłowy serwera i komponentów rdzenia SUPLA.
- `supla-docker/` - fork oficjalnego stosu Docker z overlayami dla prywatnego MQTT.
- `private-mqtt/` - samodzielny, uproszczony przykład self-hosted SUPLA z wbudowanym brokerem Mosquitto.

## Po co ten układ

To repo nie jest tylko kopią jednego upstreamu. Zawiera:

- kod bazowy `supla-core`, gdy potrzebna jest analiza protokołu, debug serwera lub własne poprawki,
- gotowy fork `supla-docker` do klasycznego deploymentu kontenerowego,
- osobny wariant `private-mqtt`, gdy potrzebny jest mniejszy, czytelny przykład z własnym brokerem.

## Szybki wybór katalogu

Jeżeli chcesz:

- uruchomić standardowy stack SUPLA z dodatkiem MQTT, zacznij od `supla-docker/`,
- uruchomić minimalny własny stack na VPS, zacznij od `private-mqtt/`,
- rozwijać lub analizować serwer SUPLA, przejdź do `supla-core/`.

## Bezpieczeństwo publikacji

Do repo nie powinny trafiać lokalne sekrety ani runtime data. Dlatego z publikacji są wyłączone między innymi:

- lokalne pliki `.env`,
- wygenerowane certyfikaty i klucze,
- katalogi `var/` z bazą, logami i danymi brokerów,
- cache Pythona.

W katalogach z konfiguracją zostają wyłącznie bezpieczne przykłady, pliki startowe i placeholdery potrzebne do uruchomienia.

## Start

Najczęstsze ścieżki:

1. `docs/instalacja-serwera-supla.md` dla pełnej instrukcji instalacji krok po kroku.
2. `private-mqtt/README.md` dla samodzielnego deploymentu z własnym brokerem.
3. `supla-docker/README.md` oraz `supla-docker/docs/mqtt-private-broker.md` dla forka opartego o oficjalny stack.
4. `supla-core/README.md` dla kodu serwera i komponentów core.
