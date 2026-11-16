package id.my.elhotelresortsanur.checklist

import android.app.Application
import android.app.NotificationChannel
import android.app.NotificationManager

class ChecklistApp : Application() {
    val repo by lazy { Repo(this) }

    override fun onCreate() {
        super.onCreate()
        // Saluran notifikasi tugas baru (kendala / permintaan tamu); penting = bunyi + muncul di atas layar.
        getSystemService(NotificationManager::class.java).createNotificationChannel(
            NotificationChannel("tugas", "Tugas baru", NotificationManager.IMPORTANCE_HIGH).apply {
                description = "Kendala dan permintaan tamu yang diteruskan kepada Anda"
            }
        )
    }
}
