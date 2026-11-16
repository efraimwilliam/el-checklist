package id.my.elhotelresortsanur.checklist

import android.app.Application

class ChecklistApp : Application() {
    val repo by lazy { Repo(this) }
}
