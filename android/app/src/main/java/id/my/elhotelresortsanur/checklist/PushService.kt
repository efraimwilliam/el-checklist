package id.my.elhotelresortsanur.checklist

import android.Manifest
import android.app.PendingIntent
import android.content.Intent
import android.content.pm.PackageManager
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch

/**
 * Notifikasi dari server (Firebase). Saat aplikasi di latar belakang, Android sendiri yang menampilkannya dan ketukan membuka
 * MainActivity dengan isi `data` (type, id) sebagai extra. Saat aplikasi sedang dibuka, notifikasi ditampilkan di sini dengan isi yang sama.
 */
class PushService : FirebaseMessagingService() {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    override fun onNewToken(token: String) {
        scope.launch { (application as ChecklistApp).repo.sendPushToken(token) }
    }

    override fun onMessageReceived(message: RemoteMessage) {
        val note = message.notification ?: return
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED &&
            android.os.Build.VERSION.SDK_INT >= 33) return
        val open = Intent(this, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_CLEAR_TOP
            message.data.forEach { (k, v) -> putExtra(k, v) }
        }
        val tag = note.tag ?: message.messageId
        val pending = PendingIntent.getActivity(this, tag.hashCode(), open, PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT)
        val built = NotificationCompat.Builder(this, "tugas")
            .setSmallIcon(R.mipmap.ic_launcher)
            .setContentTitle(note.title)
            .setContentText(note.body)
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setAutoCancel(true)
            .setContentIntent(pending)
            .build()
        NotificationManagerCompat.from(this).notify(tag, 0, built)
    }
}
