# Android app

Chrome on Android does not run extensions or userscripts, so this is a small app instead: a WebView that opens the SNCF Gares & Connexions, RFI and ADIF departure boards and injects [content.user.js](../src/content.user.js) at document start, like the Firefox extension does. On adif.es the prediction requests go through a native bridge, which plays the role of the extension's background script.

## Install

Download [on-voie-tous.apk](https://github.com/jmkjmk23456/on_voie_tous/releases/download/android-latest/on-voie-tous.apk) on the phone and open it. Android asks once to allow installing apps from the browser. Newer builds install over older ones.

## Use

- Open the app and pick SNCF, RFI or ADIF, then a station's departures.
- Or open a departure board in Chrome, tap Share and pick On Voie Tous.
- Recently opened boards show up on the start page.

## Build

GitHub Actions builds the APK on every push that touches `android/` or `src/content.user.js` and publishes it as the `android-latest` release. Locally, with the Android SDK and Gradle 8.11:

```
cd android
gradle assembleRelease
```

The signing key `app/signing.p12` is committed on purpose so that every build can update the installed app. Don't reuse it for anything published in a store.
