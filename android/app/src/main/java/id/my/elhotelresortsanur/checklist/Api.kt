package id.my.elhotelresortsanur.checklist

import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MultipartBody
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONObject
import java.io.File
import java.io.IOException
import java.util.concurrent.TimeUnit

/** Gagal memanggil server. code 0 = tidak ada koneksi / server tidak terjangkau (boleh dicoba lagi). */
class ApiException(val code: Int, message: String) : Exception(message) {
    val retryable get() = code == 0 || code == 408 || code == 429 || code >= 500
}

/** Klien /api/v1/app (Laravel). Semua panggilan memblok: jalankan dari Dispatchers.IO. */
class Api(private val token: () -> String?) {
    private val base = BuildConfig.API_URL.trimEnd('/') + "/api/v1/app"
    private val http = OkHttpClient.Builder()
        .connectTimeout(15, TimeUnit.SECONDS)
        .readTimeout(60, TimeUnit.SECONDS)
        .writeTimeout(60, TimeUnit.SECONDS)
        .build()

    fun get(path: String): JSONObject = json(request(path).get())

    fun send(method: String, path: String, body: JSONObject? = null): JSONObject =
        json(request(path).method(method, (body ?: JSONObject()).toString().toRequestBody(JSON)))

    fun upload(path: String, file: File): JSONObject = json(
        request(path).post(
            MultipartBody.Builder().setType(MultipartBody.FORM)
                .addFormDataPart("photo", file.name, file.asRequestBody("image/jpeg".toMediaType()))
                .build()
        )
    )

    fun bytes(path: String): ByteArray = execute(request(path).get()) { it }

    private fun request(path: String) = Request.Builder().url(base + path)
        .header("Accept", "application/json")
        .apply { token()?.let { header("Authorization", "Bearer $it") } }

    private fun json(builder: Request.Builder): JSONObject =
        execute(builder) { bytes -> if (bytes.isEmpty()) JSONObject() else JSONObject(String(bytes)) }

    private fun <T> execute(builder: Request.Builder, read: (ByteArray) -> T): T {
        val response = try {
            http.newCall(builder.build()).execute()
        } catch (e: IOException) {
            throw ApiException(0, "Tidak ada koneksi ke server.")
        }
        response.use {
            val bytes = try { it.body?.bytes() ?: ByteArray(0) } catch (e: IOException) {
                throw ApiException(0, "Koneksi terputus.")
            }
            if (!it.isSuccessful) throw ApiException(it.code, errorMessage(it.code, bytes))
            return read(bytes)
        }
    }

    private fun errorMessage(code: Int, bytes: ByteArray): String {
        val json = runCatching { JSONObject(String(bytes)) }.getOrNull()
        // Laravel: {"message": "...", "errors": {"field": ["..."]}}; pesan per kolom lebih jelas daripada ringkasannya.
        val first = json?.optJSONObject("errors")?.let { errors ->
            errors.keys().asSequence().firstOrNull()?.let { errors.optJSONArray(it)?.optString(0) }
        }
        return first ?: json?.optString("message")?.takeIf { it.isNotBlank() } ?: when (code) {
            401 -> "Sesi Anda sudah berakhir. Silakan login lagi."
            403 -> "Anda tidak boleh mengubah checklist ini."
            404 -> "Data tidak ditemukan."
            else -> "Server sedang bermasalah ($code). Coba lagi nanti."
        }
    }

    private companion object {
        val JSON = "application/json".toMediaType()
    }
}
