package id.my.elhotelresortsanur.checklist

import android.content.Intent
import android.net.Uri
import android.os.Build
import android.provider.Settings
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.FileProvider
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LifecycleEventEffect
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.io.File
import java.security.MessageDigest

/*
 * Pembaruan dari server sendiri (tanpa Play Store): saat aplikasi dibuka / kembali ke depan, cek `/api/v1/app/versi`.
 * Lebih baru = tawarkan Perbarui (wajib bila versi HP di bawah `min_version_code`). APK diunduh ke cache, dicek SHA-256-nya,
 * lalu diserahkan ke pemasang Android (staff menekan Instal; data & login tetap). Izin "pasang dari sumber ini" diminta sekali.
 */

private fun sha256(file: File): String = MessageDigest.getInstance("SHA-256").let { md ->
    file.inputStream().use { input -> val buf = ByteArray(64 * 1024); while (true) { val n = input.read(buf); if (n < 0) break; md.update(buf, 0, n) } }
    md.digest().joinToString("") { "%02x".format(it) }
}

@Composable
fun UpdateGate(repo: Repo) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var release by remember { mutableStateOf<JSONObject?>(null) }
    var dismissed by remember { mutableStateOf(false) }
    var progress by remember { mutableStateOf<Float?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    val apk = remember { File(context.cacheDir, "updates/eL-Checklist.apk") }

    LifecycleEventEffect(Lifecycle.Event.ON_RESUME) {
        scope.launch {
            val res = withContext(Dispatchers.IO) { runCatching { repo.api.get("/versi") }.getOrNull() } ?: return@launch
            release = res.takeIf { it.optInt("version_code") > BuildConfig.VERSION_CODE }
        }
    }

    val r = release ?: return
    val mandatory = BuildConfig.VERSION_CODE < r.optInt("min_version_code")
    if (dismissed && !mandatory) return

    fun install() {
        // Android 8+: izin "pasang aplikasi dari sumber ini" sekali; sesudah diizinkan staff menekan Perbarui lagi.
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O && !context.packageManager.canRequestPackageInstalls()) {
            error = "Izinkan \"Pasang aplikasi tidak dikenal\" untuk eL Checklist, lalu kembali dan ketuk Perbarui lagi."
            context.startActivity(Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES, Uri.parse("package:${context.packageName}")))
            return
        }
        val uri = FileProvider.getUriForFile(context, context.packageName + ".files", apk)
        context.startActivity(Intent(Intent.ACTION_VIEW).setDataAndType(uri, "application/vnd.android.package-archive")
            .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_ACTIVITY_NEW_TASK))
    }

    fun update() {
        error = null
        // Sudah terunduh utuh sebelumnya (mis. tadi menunggu izin): langsung pasang.
        if (apk.exists() && sha256(apk) == r.optString("sha256")) return install()
        progress = 0f
        scope.launch {
            try {
                withContext(Dispatchers.IO) {
                    apk.parentFile?.mkdirs()
                    repo.api.downloadTo(r.optString("path"), apk) { progress = it }
                    if (sha256(apk) != r.optString("sha256")) { apk.delete(); throw ApiException(0, "Berkas unduhan rusak. Coba lagi.") }
                }
                progress = null
                install()
            } catch (e: ApiException) {
                progress = null
                error = e.message
            }
        }
    }

    AlertDialog(
        onDismissRequest = { if (!mandatory && progress == null) dismissed = true },
        title = { Text("Versi baru ${r.optString("version_name")}") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(if (mandatory) "Versi aplikasi ini sudah tidak didukung. Perbarui dulu untuk melanjutkan." else "Versi baru eL Checklist tersedia.")
                r.s("notes")?.let { Text(it, fontSize = 14.sp, color = Muted) }
                Text("Ukuran ${"%.1f".format(r.optLong("size") / 1_048_576.0)} MB · data dan login tetap tersimpan.", fontSize = 13.sp, color = Muted)
                progress?.let { LinearProgressIndicator(progress = { it }, modifier = Modifier.fillMaxWidth(), color = Gold, trackColor = GoldSoft) }
                error?.let { Text(it, color = Red, fontSize = 14.sp) }
            }
        },
        confirmButton = { TextButton(::update, enabled = progress == null) { Text(if (progress != null) "Mengunduh…" else "Perbarui") } },
        dismissButton = if (mandatory) null else ({ TextButton({ dismissed = true }, enabled = progress == null) { Text("Nanti") } }),
    )
}
