package id.my.elhotelresortsanur.checklist

import android.content.Context
import android.os.Build
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONObject
import java.io.File
import java.util.UUID

/**
 * Sumber data aplikasi. Server = data resmi; HP menyimpan salinan (cache) supaya bisa dibuka dan diisi tanpa internet.
 *
 * Isian dikirim lewat antrean (outbox, berkas JSON): setiap perubahan ditulis ke cache lalu masuk antrean, dan antrean
 * dikirim berurutan saat online (langsung, dan lewat WorkManager bila aplikasi ditutup). Isian tugas yang sama digabung
 * (yang terbaru menang). Ditolak server (mis. checklist sudah direview) = dibuang dan diberi tahu; cache diganti data server.
 */
class Repo(private val ctx: Context) {
    // shortcut: token di SharedPreferences privat aplikasi (tidak dicadangkan), belum dienkripsi Keystore; naikkan bila HP dipakai bergantian tanpa kunci layar.
    private val prefs = ctx.getSharedPreferences("session", Context.MODE_PRIVATE)
    val api = Api { prefs.getString("token", null) }

    private val dir = File(ctx.filesDir, "data").apply { mkdirs() }
    private val photoDir = File(ctx.filesDir, "outbox-photos").apply { mkdirs() }
    private val outboxFile = File(dir, "outbox.json")
    private val lock = Any()
    private val sending = Mutex()

    /** Berubah setiap data di cache diperbarui; layar memuat ulang dari cache. */
    val version = MutableStateFlow(0L)
    val pending = MutableStateFlow(readOutbox().length())
    val offline = MutableStateFlow(false)
    val message = MutableStateFlow<String?>(null)
    val loggedIn = MutableStateFlow(prefs.getString("token", null) != null)
    /** Menu Kendala (saklar fitur + izin di server, dari /login dan /me). */
    val kendala = MutableStateFlow(prefs.getBoolean("kendala", false))
    val permintaan = MutableStateFlow(prefs.getBoolean("permintaan", false))
    val papan = MutableStateFlow(prefs.getBoolean("papan", false))
    val riwayat = MutableStateFlow(prefs.getBoolean("riwayat", false))
    val obrolan = MutableStateFlow(prefs.getBoolean("obrolan", false))
    val chronology = MutableStateFlow(prefs.getBoolean("chronology", false))

    /** Tampilan checklist: kartu (satu tugas per layar, geser) atau daftar. Diingat di HP ini, bawaan kartu. */
    var cardView: Boolean
        get() = prefs.getBoolean("card_view", true)
        set(value) { prefs.edit().putBoolean("card_view", value).apply() }

    val userName: String? get() = prefs.getString("name", null)
    val userDetail: String? get() = prefs.getString("detail", null)

    // ---- Login ----

    suspend fun login(username: String, pin: String) = io {
        val res = api.send("POST", "/login", JSONObject().put("username", username).put("pin", pin).put("device", Build.MODEL))
        val user = res.getJSONObject("user")
        // Orang lain masuk di HP ini: data dan antrean orang sebelumnya tidak boleh terkirim atas namanya.
        if (prefs.getLong("user_id", -1) != user.getLong("id")) clearData()
        prefs.edit().putString("token", res.getString("token")).putLong("user_id", user.getLong("id")).apply()
        profile(user)
        message.value = null // dibersihkan di sini: layar login sudah hilang begitu loggedIn berubah
        loggedIn.value = true
        flush()
    }

    /** Profil + menu yang boleh dipakai; dipanggil saat aplikasi dibuka supaya saklar fitur dari web ikut berlaku. */
    suspend fun refreshMe() = io {
        guard { profile(api.get("/me").getJSONObject("user")) }
        registerPush()
    }

    /** Token notifikasi Firebase HP ini dikirim ke server (menempel pada token login). Gagal = dicoba lagi saat aplikasi dibuka. */
    private suspend fun registerPush() {
        val token = runCatching {
            kotlinx.coroutines.suspendCancellableCoroutine { cont ->
                com.google.firebase.messaging.FirebaseMessaging.getInstance().token
                    .addOnCompleteListener { cont.resumeWith(Result.success(if (it.isSuccessful) it.result else null)) }
            }
        }.getOrNull() ?: return
        sendPushToken(token)
    }

    fun sendPushToken(token: String) {
        if (prefs.getString("token", null) == null) return
        runCatching { api.send("POST", "/push-token", JSONObject().put("token", token)) }
    }

    private fun profile(user: JSONObject) {
        val features = user.optJSONObject("features")
        val kendalaOn = features?.optBoolean("kendala") == true
        val permintaanOn = features?.optBoolean("permintaan") == true
        prefs.edit()
            .putString("name", user.optString("name"))
            .putString("detail", listOf(user.optString("position"), user.optString("department")).filter { it.isNotBlank() && it != "null" }.joinToString(" · "))
            .putBoolean("kendala", kendalaOn)
            .putBoolean("permintaan", permintaanOn)
            .putBoolean("papan", features?.optBoolean("papan") == true)
            .putBoolean("riwayat", features?.optBoolean("riwayat") == true)
            .putBoolean("obrolan", features?.optBoolean("obrolan") == true)
            .putBoolean("chronology", features?.optBoolean("chronology") == true)
            .apply()
        kendala.value = kendalaOn
        permintaan.value = permintaanOn
        papan.value = features?.optBoolean("papan") == true
        riwayat.value = features?.optBoolean("riwayat") == true
        obrolan.value = features?.optBoolean("obrolan") == true
        chronology.value = features?.optBoolean("chronology") == true
    }

    suspend fun logout() = io {
        runCatching { api.send("POST", "/logout") }
        clearData()
        prefs.edit().clear().apply()
        loggedIn.value = false
    }

    fun sessionEnded() {
        prefs.edit().remove("token").apply() // antrean & cache tetap: login lagi dengan akun yang sama lalu dikirim
        loggedIn.value = false
        message.value = "Sesi Anda sudah berakhir. Silakan login lagi."
    }

    private fun clearData() = synchronized(lock) {
        dir.listFiles()?.forEach { it.delete() }
        photoDir.listFiles()?.forEach { it.delete() }
        pending.value = 0
        bump()
    }

    // ---- Cache ----

    fun cachedList(): List<Summary>? = read("list.json")?.let { o -> o.getJSONArray("checklists").let { a -> (0 until a.length()).map { parseSummary(a.getJSONObject(it)) } } }

    fun cachedDetail(id: Long): JSONObject? = read("checklist-$id.json")

    /** Mengambil daftar dan setiap checklist yang bisa diisi, supaya semuanya tersedia offline. */
    suspend fun refresh() = io {
        guard {
            val list = api.get("/checklists")
            write("list.json", list)
            val ids = list.getJSONArray("checklists").let { a -> (0 until a.length()).map { a.getJSONObject(it).getLong("id") } }
            ids.forEach { refreshDetailNow(it) }
            // Cache checklist yang sudah tidak ada di daftar dibuang (kecuali masih ada antrean).
            dir.listFiles { f -> f.name.startsWith("checklist-") }?.forEach { f ->
                val id = f.name.removePrefix("checklist-").removeSuffix(".json").toLongOrNull()
                if (id != null && id !in ids && !hasPending(id)) f.delete()
            }
            bump()
        }
    }

    suspend fun refreshDetail(id: Long) = io { guard { refreshDetailNow(id); bump() } }

    /** Isian yang belum terkirim tidak boleh tertimpa data server lama. */
    private fun refreshDetailNow(id: Long) {
        if (hasPending(id)) return
        write("checklist-$id.json", api.get("/checklists/$id").getJSONObject("checklist"))
    }

    fun saveDetail(id: Long, detail: JSONObject) {
        write("checklist-$id.json", detail)
        bump()
    }

    // ---- Antrean (outbox) ----

    fun saveItem(checklist: Long, item: Long, body: JSONObject) =
        enqueue(checklist, "PATCH", "/checklists/$checklist/items/$item", body, key = "item:$checklist:$item")

    /** Tugas tambahan dikirim utuh (server mengganti seluruh daftar; judul kosong dibuang). */
    fun saveExtras(checklist: Long, extras: JSONArray) {
        val clean = JSONArray()
        for (i in 0 until extras.length()) extras.getJSONObject(i).let { e ->
            clean.put(JSONObject().put("title", e.opt("title") ?: JSONObject.NULL).put("status", e.opt("status") ?: JSONObject.NULL).put("note", e.opt("note") ?: JSONObject.NULL))
        }
        enqueue(checklist, "PATCH", "/checklists/$checklist", JSONObject().put("extras", clean), key = "extras:$checklist")
    }

    fun saveNote(checklist: Long, note: String?) =
        enqueue(checklist, "PATCH", "/checklists/$checklist", JSONObject().put("note", note ?: JSONObject.NULL), key = "meta:$checklist")

    /** Foto (sudah dikecilkan) dipindah ke folder antrean; mengembalikan path barunya untuk ditampilkan. */
    fun addPhoto(checklist: Long, item: Long, jpeg: File): String {
        val target = File(photoDir, "${UUID.randomUUID()}.jpg")
        jpeg.copyTo(target); jpeg.delete()
        enqueue(checklist, "UPLOAD", "/checklists/$checklist/items/$item/photos", null, file = target.path)
        return target.path
    }

    fun deletePhoto(checklist: Long, photo: Photo) {
        if (photo.local != null) {
            synchronized(lock) {
                writeOutbox(readOutbox().list().filterNot { it.optString("file") == photo.local })
                File(photo.local).delete()
            }
        } else if (photo.id != null) {
            enqueue(checklist, "DELETE", "/checklists/$checklist/photos/${photo.id}", null)
        }
    }

    fun hasPending(checklist: Long) = synchronized(lock) { readOutbox().list().any { it.getLong("checklist") == checklist } }

    fun pendingFor(checklist: Long) = synchronized(lock) { readOutbox().list().count { it.getLong("checklist") == checklist } }

    private fun enqueue(checklist: Long, method: String, path: String, body: JSONObject?, key: String? = null, file: String? = null) {
        val op = JSONObject().put("seq", UUID.randomUUID().toString()).put("checklist", checklist).put("method", method).put("path", path)
        body?.let { op.put("body", it) }
        key?.let { op.put("key", it) }
        file?.let { op.put("file", it) }
        synchronized(lock) {
            val ops = readOutbox().list().toMutableList()
            val same = if (key == null) -1 else ops.indexOfFirst { it.optString("key") == key }
            if (same >= 0) ops[same] = op else ops.add(op)
            writeOutbox(ops)
        }
        schedule(ctx)
    }

    /** Mengirim antrean berurutan. true = semua terkirim. */
    suspend fun flush(): Boolean = io {
        sending.withLock {
            val touched = mutableSetOf<Long>()
            while (true) {
                if (prefs.getString("token", null) == null) return@withLock false
                val op = synchronized(lock) { readOutbox().list().firstOrNull() } ?: break
                touched += op.getLong("checklist")
                try {
                    send(op)
                    offline.value = false
                } catch (e: ApiException) {
                    when {
                        e.code == 401 -> { sessionEnded(); return@withLock false }
                        e.retryable -> { offline.value = e.code == 0; return@withLock false }
                        else -> message.value = "Perubahan tidak tersimpan: ${e.message}" // ditolak; data server dimuat ulang di bawah
                    }
                }
                synchronized(lock) {
                    writeOutbox(readOutbox().list().filterNot { it.getString("seq") == op.getString("seq") })
                    op.optString("file").takeIf { it.isNotEmpty() }?.let { File(it).delete() }
                }
            }
            touched.forEach { runCatching { refreshDetailNow(it) } }
            if (touched.isNotEmpty()) runCatching { write("list.json", api.get("/checklists")) }
            bump()
            true
        }
    }

    // shortcut: unggah foto yang terputus SETELAH server menyimpan bisa terkirim dua kali (maks. foto per tugas tetap dijaga server); tambahkan kunci idempoten bila sering terjadi.
    private fun send(op: JSONObject) {
        val path = op.getString("path")
        when (val method = op.getString("method")) {
            "UPLOAD" -> File(op.getString("file")).takeIf { it.exists() }?.let { api.upload(path, it) }
            else -> api.send(method, path, op.optJSONObject("body"))
        }
    }

    private fun guard(block: () -> Unit) {
        try {
            block()
            offline.value = false
        } catch (e: ApiException) {
            when {
                e.code == 401 -> sessionEnded()
                e.code == 0 -> offline.value = true
                else -> message.value = e.message
            }
        }
    }

    // ---- Berkas ----

    private fun read(name: String): JSONObject? = synchronized(lock) {
        File(dir, name).takeIf { it.exists() }?.let { runCatching { JSONObject(it.readText()) }.getOrNull() }
    }

    private fun write(name: String, json: JSONObject) = synchronized(lock) {
        val tmp = File(dir, "$name.tmp")
        tmp.writeText(json.toString())
        tmp.renameTo(File(dir, name)) // ganti utuh: berkas tidak pernah setengah tertulis
    }

    private fun readOutbox(): JSONArray = synchronized(lock) {
        if (outboxFile.exists()) runCatching { JSONArray(outboxFile.readText()) }.getOrDefault(JSONArray()) else JSONArray()
    }

    private fun writeOutbox(ops: List<JSONObject>) {
        val tmp = File(dir, "outbox.json.tmp")
        tmp.writeText(JSONArray(ops).toString())
        tmp.renameTo(outboxFile)
        pending.value = ops.size
    }

    private fun bump() { version.value = version.value + 1 }

    private fun JSONArray.list() = (0 until length()).map { getJSONObject(it) }

    private suspend fun <T> io(block: suspend () -> T): T = withContext(Dispatchers.IO) { block() }

    companion object {
        fun schedule(ctx: Context) {
            val request = OneTimeWorkRequestBuilder<SyncWorker>()
                .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
                .build()
            WorkManager.getInstance(ctx).enqueueUniqueWork("sync", ExistingWorkPolicy.KEEP, request)
        }
    }
}

/** Mengirim antrean di latar belakang begitu ada internet, walau aplikasi sudah ditutup. */
class SyncWorker(ctx: Context, params: WorkerParameters) : CoroutineWorker(ctx, params) {
    override suspend fun doWork(): Result {
        val repo = (applicationContext as ChecklistApp).repo
        return if (repo.flush() || !repo.loggedIn.value) Result.success() else Result.retry()
    }
}
