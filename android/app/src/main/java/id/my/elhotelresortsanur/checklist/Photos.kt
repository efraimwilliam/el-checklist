package id.my.elhotelresortsanur.checklist

import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Matrix
import android.net.Uri
import androidx.exifinterface.media.ExifInterface
import java.io.File
import java.io.InputStream
import kotlin.math.max

/** Foto dikecilkan di HP sebelum dikirim (sama dengan web: sisi terpanjang 1280 px, JPEG 80), diputar sesuai EXIF. */
object Photos {
    const val MAX_EDGE = 1280

    fun compress(ctx: Context, uri: Uri, out: File): Boolean = runCatching {
        val open = { ctx.contentResolver.openInputStream(uri) ?: error("tidak bisa dibuka") }
        val rotation = open().use { ExifInterface(it).rotationDegrees }
        val bitmap = decode(open, MAX_EDGE) ?: return false
        val scale = MAX_EDGE.toFloat() / max(bitmap.width, bitmap.height)
        val matrix = Matrix().apply {
            if (scale < 1f) postScale(scale, scale)
            postRotate(rotation.toFloat())
        }
        val result = Bitmap.createBitmap(bitmap, 0, 0, bitmap.width, bitmap.height, matrix, true)
        out.outputStream().use { result.compress(Bitmap.CompressFormat.JPEG, 80, it) }
        true
    }.getOrDefault(false)

    /** Gambar kecil untuk tampilan (tanpa memuat foto ukuran penuh ke memori). */
    fun thumbnail(bytes: ByteArray, edge: Int): Bitmap? = decode({ bytes.inputStream() }, edge)

    fun thumbnail(file: File, edge: Int): Bitmap? = if (file.exists()) decode({ file.inputStream() }, edge) else null

    private fun decode(open: () -> InputStream, edge: Int): Bitmap? {
        val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
        open().use { BitmapFactory.decodeStream(it, null, bounds) }
        if (bounds.outWidth <= 0) return null
        var sample = 1
        while (max(bounds.outWidth, bounds.outHeight) / (sample * 2) >= edge) sample *= 2
        return open().use { BitmapFactory.decodeStream(it, null, BitmapFactory.Options().apply { inSampleSize = sample }) }
    }
}
