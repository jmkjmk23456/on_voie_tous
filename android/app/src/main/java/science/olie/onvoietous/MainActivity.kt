package science.olie.onvoietous

import android.annotation.SuppressLint
import android.app.Activity
import android.content.ActivityNotFoundException
import android.content.Intent
import android.graphics.Bitmap
import android.net.Uri
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.view.ViewGroup
import android.webkit.WebResourceRequest
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.webkit.WebViewCompat
import androidx.webkit.WebViewFeature
import org.json.JSONArray
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import java.util.concurrent.Executors

/**
 * A WebView that opens the SNCF, RFI and ADIF departure boards and injects
 * src/content.user.js into them, the way the Firefox extension does.
 */
class MainActivity : Activity() {

    private lateinit var webView: WebView
    private val main = Handler(Looper.getMainLooper())
    private val network = Executors.newFixedThreadPool(2)
    private var injectedAtDocumentStart = false
    private lateinit var userscript: String

    @SuppressLint("SetJavaScriptEnabled")
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        userscript = assets.open("content.user.js").bufferedReader().use { it.readText() }

        webView = WebView(this)
        webView.layoutParams = ViewGroup.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT
        )
        setContentView(webView)

        webView.settings.apply {
            javaScriptEnabled = true
            domStorageEnabled = true
            loadWithOverviewMode = true
            useWideViewPort = true
            builtInZoomControls = true
            displayZoomControls = false
        }

        installUserscript()
        webView.webViewClient = Client()

        if (savedInstanceState != null) {
            webView.restoreState(savedInstanceState)
        } else {
            webView.loadUrl(urlFromIntent(intent) ?: START_URL)
        }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        urlFromIntent(intent)?.let { webView.loadUrl(it) }
    }

    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        webView.saveState(outState)
    }

    @Deprecated("Still called while predictive back is not enabled in the manifest")
    override fun onBackPressed() {
        if (webView.canGoBack()) webView.goBack() else super.onBackPressed()
    }

    override fun onDestroy() {
        network.shutdownNow()
        webView.destroy()
        super.onDestroy()
    }

    private fun installUserscript() {
        // ADIF's pages only accept the prediction requests through the extension's
        // background script; here that role is played by a native message listener.
        if (WebViewFeature.isFeatureSupported(WebViewFeature.WEB_MESSAGE_LISTENER)) {
            WebViewCompat.addWebMessageListener(
                webView, "OnVoieBridge", setOf("https://www.adif.es")
            ) { _, message, _, _, reply ->
                val request = runCatching { JSONObject(message.data ?: "") }.getOrNull()
                    ?: return@addWebMessageListener
                network.execute {
                    val response = forwardAdifRequest(request)
                    main.post { reply.postMessage(response.toString()) }
                }
            }
        }

        if (WebViewFeature.isFeatureSupported(WebViewFeature.DOCUMENT_START_SCRIPT)) {
            WebViewCompat.addDocumentStartJavaScript(webView, GM_SHIM + userscript, BOARD_ORIGINS)
            injectedAtDocumentStart = true
        }
    }

    private fun forwardAdifRequest(request: JSONObject): JSONObject {
        val id = request.optString("id")
        val url = request.optString("url")
        val path = url.removePrefix(PREDICT_SERVER_URL)
        if (path == url || path !in ADIF_PATHS) return JSONObject().put("id", id).put("error", true)
        val body = request.optString("body").takeIf { request.has("body") && !request.isNull("body") }
        return try {
            val connection = URL(url).openConnection() as HttpURLConnection
            connection.connectTimeout = 3000
            connection.readTimeout = 3000
            connection.instanceFollowRedirects = false
            connection.setRequestProperty("Content-Type", "application/json")
            if (body != null) {
                connection.requestMethod = "POST"
                connection.doOutput = true
                connection.outputStream.use { it.write(body.toByteArray()) }
            }
            val status = connection.responseCode
            val text = (if (status < 400) connection.inputStream else connection.errorStream)
                ?.bufferedReader()?.use { it.readText() } ?: ""
            connection.disconnect()
            JSONObject().put("id", id).put("status", status).put("body", text)
        } catch (e: Exception) {
            JSONObject().put("id", id).put("error", true)
        }
    }

    private fun urlFromIntent(intent: Intent?): String? {
        val candidate = when (intent?.action) {
            Intent.ACTION_VIEW -> intent.dataString
            Intent.ACTION_SEND -> intent.getStringExtra(Intent.EXTRA_TEXT)
                ?.let { Regex("https://\\S+").find(it)?.value }
            else -> null
        } ?: return null
        val host = Uri.parse(candidate).host ?: return null
        return candidate.takeIf { BOARD_ORIGINS.any { it.endsWith("//$host") } }
    }

    private fun rememberBoard(url: String, title: String?) {
        val looksLikeBoard = url.contains("/horaires") || url.contains("/Monitor") ||
            url.contains("station=") || url.startsWith("https://www.adif.es/w/")
        if (!looksLikeBoard) return
        val prefs = getSharedPreferences("recent", MODE_PRIVATE)
        val old = JSONArray(prefs.getString("boards", "[]"))
        val updated = JSONArray().put(JSONObject().put("url", url).put("title", title ?: url))
        for (i in 0 until old.length()) {
            val item = old.getJSONObject(i)
            if (item.getString("url") != url && updated.length() < 5) updated.put(item)
        }
        prefs.edit().putString("boards", updated.toString()).apply()
    }

    private inner class Client : WebViewClient() {
        override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
            val uri = request.url
            if (uri.scheme == "http" || uri.scheme == "https") return false
            try {
                startActivity(Intent(Intent.ACTION_VIEW, uri))
            } catch (e: ActivityNotFoundException) {
                // No app for this link, e.g. a tel: link on a tablet.
            }
            return true
        }

        override fun onPageStarted(view: WebView, url: String, favicon: Bitmap?) {
            // Older WebViews: inject as early as we can. Predictions may then miss
            // the first request on some pages, but a reload picks them up.
            if (!injectedAtDocumentStart && BOARD_ORIGINS.any { url.startsWith("$it/") }) {
                view.evaluateJavascript(GM_SHIM + userscript, null)
            }
        }

        override fun onPageFinished(view: WebView, url: String) {
            if (url == START_URL) {
                val boards = getSharedPreferences("recent", MODE_PRIVATE).getString("boards", "[]")
                view.evaluateJavascript("window.showRecent && showRecent($boards)", null)
            } else {
                rememberBoard(url, view.title)
            }
        }
    }

    companion object {
        const val START_URL = "file:///android_asset/start.html"
        const val PREDICT_SERVER_URL = "https://compute.olie.science/on_voie_tous"
        val ADIF_PATHS = setOf("/adif-web/stations", "/adif-web/predict")
        val BOARD_ORIGINS = setOf(
            "https://www.garesetconnexions.sncf",
            "https://iechub.rfi.it",
            "https://pantallas-estaciones.vercel.app",
            "https://www.adif.es",
        )

        /** Provides the userscript's GM_xmlhttpRequest on adif.es via the native bridge. */
        val GM_SHIM = """
            (function () {
                if (location.hostname !== 'www.adif.es' || !window.OnVoieBridge ||
                    typeof window.GM_xmlhttpRequest === 'function') return;
                const bridge = window.OnVoieBridge;
                const pending = new Map();
                let next = 0;
                bridge.addEventListener('message', event => {
                    let message;
                    try { message = JSON.parse(event.data); } catch { return; }
                    const done = pending.get(message.id);
                    if (done) { pending.delete(message.id); done(message); }
                });
                window.GM_xmlhttpRequest = details => {
                    const id = String(++next);
                    const timer = setTimeout(() => {
                        if (pending.delete(id)) details.ontimeout && details.ontimeout();
                    }, (details.timeout || 5000) + 1000);
                    pending.set(id, message => {
                        clearTimeout(timer);
                        if (message.error) details.onerror && details.onerror();
                        else details.onload && details.onload({status: message.status, responseText: message.body});
                    });
                    bridge.postMessage(JSON.stringify({id, url: details.url, method: details.method,
                        body: details.data === undefined ? null : details.data}));
                };
            })();
        """.trimIndent() + "\n"
    }
}
