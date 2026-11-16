package id.my.elhotelresortsanur.checklist

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.BackHandler
import androidx.activity.compose.setContent
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.material3.lightColorScheme
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LifecycleEventEffect
import kotlinx.coroutines.launch
import java.time.LocalDate
import java.time.format.DateTimeFormatter
import java.util.Locale

// Warna dari web (resources/css/app.css).
val Ink = Color(0xFF1B1511)
val Gold = Color(0xFFB08A3E)
val GoldSoft = Color(0xFFF6EFDF)
val Cream = Color(0xFFFAF7F2)
val Muted = Color(0xFF7B6D61)
val Green = Color(0xFF2F7D4F)
val Amber = Color(0xFFB7791F)
val Red = Color(0xFFB23A24)

private val ID = Locale("id", "ID")
fun formatDate(date: String): String =
    runCatching { LocalDate.parse(date).format(DateTimeFormatter.ofPattern("EEEE, d MMM yyyy", ID)) }.getOrDefault(date)

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val repo = (application as ChecklistApp).repo
        setContent {
            MaterialTheme(colorScheme = lightColorScheme(primary = Gold, onPrimary = Color.White, background = Cream, surface = Color.White, secondary = Ink)) {
                val loggedIn by repo.loggedIn.collectAsState()
                var open by rememberSaveable { mutableStateOf<Long?>(null) }
                when {
                    !loggedIn -> LoginScreen(repo)
                    open != null -> {
                        BackHandler { open = null }
                        FillScreen(repo, open!!, onBack = { open = null })
                    }
                    else -> ListScreen(repo, onOpen = { open = it })
                }
            }
        }
    }
}

@Composable
fun Banner(repo: Repo) {
    val pending by repo.pending.collectAsState()
    val offline by repo.offline.collectAsState()
    val message by repo.message.collectAsState()
    message?.let {
        Row(Modifier.fillMaxWidth().background(Color(0xFFFBE9E5)).padding(horizontal = 16.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
            Text(it, color = Red, modifier = Modifier.weight(1f), fontSize = 14.sp)
            TextButton(onClick = { repo.message.value = null }) { Text("Tutup") }
        }
    }
    if (offline || pending > 0) {
        Text(
            when {
                offline && pending > 0 -> "Offline · $pending perubahan tersimpan di HP, dikirim otomatis saat ada internet"
                offline -> "Offline · menampilkan data terakhir"
                else -> "Mengirim $pending perubahan…"
            },
            Modifier.fillMaxWidth().background(GoldSoft).padding(horizontal = 16.dp, vertical = 8.dp), fontSize = 13.sp, color = Ink,
        )
    }
}

@Composable
fun LoginScreen(repo: Repo) {
    val scope = rememberCoroutineScope()
    var username by rememberSaveable { mutableStateOf("") }
    var pin by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val pending by repo.pending.collectAsState()
    val message by repo.message.collectAsState()

    fun submit() {
        if (busy || username.isBlank() || pin.isBlank()) return
        busy = true; error = null
        scope.launch {
            try {
                repo.login(username.trim(), pin)
                repo.message.value = null
                repo.refresh()
            } catch (e: ApiException) {
                error = e.message
            } finally {
                busy = false; pin = ""
            }
        }
    }

    Box(Modifier.fillMaxSize().background(Ink).imePadding(), contentAlignment = Alignment.Center) {
        Card(Modifier.padding(20.dp).fillMaxWidth(), shape = RoundedCornerShape(20.dp), colors = CardDefaults.cardColors(containerColor = Color.White)) {
            Column(Modifier.padding(24.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
                Text("éL Hotel & Resort Sanur", color = Gold, fontWeight = FontWeight.SemiBold, fontSize = 13.sp)
                Text("Daily Department Checklist", fontSize = 22.sp, fontWeight = FontWeight.Bold, color = Ink)
                Text("Masuk dengan username dan PIN absensi.", color = Muted, fontSize = 14.sp)
                (error ?: message)?.let { Text(it, color = Red, fontSize = 14.sp) }
                if (pending > 0) Text("$pending perubahan menunggu dikirim. Masuk lagi dengan akun yang sama untuk mengirimnya.", color = Ink, fontSize = 13.sp)
                OutlinedTextField(
                    username, { username = it }, label = { Text("Username") }, singleLine = true, modifier = Modifier.fillMaxWidth(),
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Ascii, imeAction = ImeAction.Next),
                )
                OutlinedTextField(
                    pin, { pin = it }, label = { Text("PIN / password") }, singleLine = true, modifier = Modifier.fillMaxWidth(),
                    visualTransformation = PasswordVisualTransformation(),
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password, imeAction = ImeAction.Done),
                    keyboardActions = KeyboardActions(onDone = { submit() }),
                )
                Button(::submit, Modifier.fillMaxWidth().height(50.dp), enabled = !busy, colors = ButtonDefaults.buttonColors(containerColor = Ink)) {
                    if (busy) CircularProgressIndicator(Modifier.width(22.dp).height(22.dp), color = Color.White, strokeWidth = 2.dp) else Text("Masuk")
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ListScreen(repo: Repo, onOpen: (Long) -> Unit) {
    val scope = rememberCoroutineScope()
    val version by repo.version.collectAsState()
    val list = remember(version) { repo.cachedList() }
    var refreshing by remember { mutableStateOf(false) }
    var confirmLogout by remember { mutableStateOf(false) }
    val pending by repo.pending.collectAsState()

    fun reload() = scope.launch { refreshing = true; repo.flush(); repo.refresh(); refreshing = false }
    LaunchedEffect(Unit) { reload() }
    LifecycleEventEffect(Lifecycle.Event.ON_RESUME) { scope.launch { repo.flush() } }

    Scaffold(
        topBar = {
            TopAppBar(
                title = {
                    Column {
                        Text("Checklist saya", fontWeight = FontWeight.SemiBold)
                        Text(listOfNotNull(repo.userName, repo.userDetail?.takeIf { it.isNotBlank() }).joinToString(" · "), fontSize = 12.sp, color = Color(0xFFDCCDB6))
                    }
                },
                actions = { TextButton(onClick = { confirmLogout = true }) { Text("Keluar", color = Color.White) } },
                colors = TopAppBarDefaults.topAppBarColors(containerColor = Ink, titleContentColor = Color.White),
            )
        },
        containerColor = Cream,
    ) { padding ->
        Column(Modifier.padding(padding)) {
            Banner(repo)
            if (confirmLogout) {
                Card(Modifier.padding(16.dp), colors = CardDefaults.cardColors(containerColor = Color.White)) {
                    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        Text(if (pending > 0) "Masih ada $pending perubahan yang belum terkirim dan akan HILANG bila keluar sekarang." else "Keluar dari aplikasi?", color = if (pending > 0) Red else Ink)
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            TextButton(onClick = { confirmLogout = false }) { Text("Batal") }
                            Button({ scope.launch { repo.logout() } }, colors = ButtonDefaults.buttonColors(containerColor = Red)) { Text("Keluar") }
                        }
                    }
                }
            }
            PullToRefreshBox(isRefreshing = refreshing, onRefresh = { reload() }, modifier = Modifier.fillMaxSize()) {
                LazyColumn(contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp), modifier = Modifier.fillMaxSize()) {
                    if (list == null) {
                        item { Text(if (refreshing) "Memuat…" else "Belum ada data. Tarik ke bawah untuk memuat.", color = Muted) }
                    } else if (list.isEmpty()) {
                        item { Text("Belum ada checklist untuk 7 hari terakhir. Checklist dibuatkan oleh HOD / HR.", color = Muted) }
                    }
                    items(list.orEmpty(), key = { it.id }) { c -> ChecklistCard(repo, c, version) { onOpen(c.id) } }
                }
            }
        }
    }
}

@Composable
private fun ChecklistCard(repo: Repo, summary: Summary, version: Long, onClick: () -> Unit) {
    // Progres dari cache detail (memuat isian yang belum terkirim) bila ada.
    val progress = remember(summary, version) { repo.cachedDetail(summary.id)?.let { localProgress(parseDetail(it)) } ?: summary.progress }
    val waiting = remember(summary, version) { repo.pendingFor(summary.id) }
    val (label, color) = when {
        summary.status == "reviewed" -> "Direview GM/HR" to Green
        summary.status == "hod_reviewed" -> "Direview HOD" to Green
        progress.complete -> "Lengkap" to Green
        progress.filled == 0 -> "Belum diisi" to Muted
        else -> "Belum lengkap" to Amber
    }
    Card(Modifier.fillMaxWidth().clickable(onClick = onClick), shape = RoundedCornerShape(16.dp), colors = CardDefaults.cardColors(containerColor = Color.White)) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(formatDate(summary.date), fontWeight = FontWeight.SemiBold, color = Ink, modifier = Modifier.weight(1f))
                Text(label, color = color, fontSize = 13.sp, fontWeight = FontWeight.Medium)
            }
            Text(summary.position, color = Muted, fontSize = 14.sp)
            LinearProgressIndicator(
                progress = { if (progress.total == 0) 0f else progress.filled.toFloat() / progress.total },
                modifier = Modifier.fillMaxWidth().height(6.dp), color = Gold, trackColor = GoldSoft,
            )
            Row {
                Text("${progress.filled}/${progress.total} terisi" + (progress.score?.let { " · Skor $it%" } ?: ""), fontSize = 13.sp, color = Ink, modifier = Modifier.weight(1f))
                if (waiting > 0) Text("$waiting belum terkirim", fontSize = 13.sp, color = Amber)
            }
        }
    }
}
