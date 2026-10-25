plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

// The app ships the same userscript as the browser extension, copied from ../src at build time.
val copyUserscript by tasks.registering(Copy::class) {
    from(rootProject.file("../src/content.user.js"))
    into(layout.buildDirectory.dir("generated/userscript"))
}

android {
    namespace = "science.olie.onvoietous"
    compileSdk = 35

    defaultConfig {
        applicationId = "science.olie.onvoietous.android"
        minSdk = 26
        targetSdk = 35
        versionCode = (System.getenv("GITHUB_RUN_NUMBER") ?: "1").toInt()
        versionName = "0.12-android." + (System.getenv("GITHUB_RUN_NUMBER") ?: "dev")
    }

    // Fixed key so that newer APKs install as updates over older ones.
    // It lives in the repo on purpose: this app is only sideloaded, not published in a store.
    signingConfigs {
        create("sideload") {
            storeFile = file("signing.p12")
            storeType = "pkcs12"
            storePassword = "onvoietous"
            keyAlias = "onvoie"
            keyPassword = "onvoietous"
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            signingConfig = signingConfigs.getByName("sideload")
        }
        debug {
            signingConfig = signingConfigs.getByName("sideload")
        }
    }

    sourceSets["main"].assets.srcDir(layout.buildDirectory.dir("generated/userscript"))

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions {
        jvmTarget = "17"
    }
}

tasks.named("preBuild") { dependsOn(copyUserscript) }

dependencies {
    implementation("androidx.webkit:webkit:1.12.1")
}
