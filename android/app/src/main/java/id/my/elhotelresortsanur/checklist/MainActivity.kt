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
import androidx.compose.material3.Badge
import androidx.compose.material3.BadgedBox
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
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
    /** Notifikasi yang diketuk: ("kendala", id) membuka kendala itu, ("permintaan", id) membuka daftar permintaan. */
    private val opened = kotlinx.coroutines.flow.MutableStateFlow<Pair<String, Long>?>(null)

    private fun readNotification(intent: android.content.Intent?) {
        val type = intent?.getStringExtra("type") ?: return
        opened.value = type to (intent.getStringExtra("id")?.toLongOrNull() ?: 0L)
        intent.removeExtra("type")
    }

    override fun onNewIntent(intent: android.content.Intent) {
        super.onNewIntent(intent)
        readNotification(intent)
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        if (savedInstanceState == null) readNotification(intent)
        val repo = (application as ChecklistApp).repo
        setContent {
            MaterialTheme(colorScheme = lightColorScheme(primary = Gold, onPrimary = Color.White, background = Cream, surface = Color.White, secondary = Ink,
                secondaryContainer = GoldSoft, onSecondaryContainer = Ink, surfaceContainerHigh = Color.White)) {
                val loggedIn by repo.loggedIn.collectAsState()
                val kendala by repo.kendala.collectAsState()
                val permintaan by repo.permintaan.collectAsState()
                val papan by repo.papan.collectAsState()
                val riwayat by repo.riwayat.collectAsState()
                var history by rememberSaveable { mutableStateOf(false) }
                val obrolan by repo.obrolan.collectAsState()
                var chat by rememberSaveable { mutableStateOf<Long?>(null) }
                var contacts by rememberSaveable { mutableStateOf(false) }
                val chronologyOn by repo.chronology.collectAsState()
                var chronoList by rememberSaveable { mutableStateOf(false) }
                var chrono by rememberSaveable { mutableStateOf<Long?>(null) }
                var chronoForm by rememberSaveable { mutableStateOf<Long?>(null) } // 0 = laporan baru
                // Angka belum dibaca di tab Obrolan: dimuat saat aplikasi dibuka (daftar obrolan memperbaruinya selama terbuka).
                LaunchedEffect(loggedIn, obrolan) { if (loggedIn && obrolan) repo.call { it.get("/obrolan") }?.let { chatUnread.intValue = it.optInt("unread") } }
                var newRequest by rememberSaveable { mutableStateOf(false) }
                var open by rememberSaveable { mutableStateOf<Long?>(null) }
                var tab by rememberSaveable { mutableStateOf("checklist") }
                var issue by rememberSaveable { mutableStateOf<Long?>(null) }
                var newIssue by rememberSaveable { mutableStateOf(false) }
                LaunchedEffect(loggedIn) { if (loggedIn) repo.refreshMe() }
                // Android 13+: izin notifikasi diminta sekali setelah login.
                val askNotify = androidx.activity.compose.rememberLauncherForActivityResult(androidx.activity.result.contract.ActivityResultContracts.RequestPermission()) {}
                LaunchedEffect(loggedIn) {
                    if (loggedIn && android.os.Build.VERSION.SDK_INT >= 33 &&
                        checkSelfPermission(android.Manifest.permission.POST_NOTIFICATIONS) != android.content.pm.PackageManager.PERMISSION_GRANTED
                    ) askNotify.launch(android.Manifest.permission.POST_NOTIFICATIONS)
                }
                val link by opened.collectAsState()
                LaunchedEffect(link, loggedIn) {
                    val (type, id) = link ?: return@LaunchedEffect
                    if (!loggedIn) return@LaunchedEffect // dibuka sesudah login
                    open = null; newIssue = false; newRequest = false; history = false; contacts = false
                    when (type) {
                        "obrolan" -> { tab = "obrolan"; chat = id; issue = null }
                        "kendala" -> { tab = "kendala"; issue = id }
                        "permintaan" -> { tab = "permintaan"; issue = null }
                    }
                    opened.value = null
                }
                when {
                    !loggedIn -> LoginScreen(repo)
                    open != null -> {
                        BackHandler { open = null }
                        FillScreen(repo, open!!, onBack = { open = null })
                    }
                    chat != null -> {
                        BackHandler { chat = null }
                        ChatScreen(repo, chat!!, onBack = { chat = null }, onCard = { type, id ->
                            when (type) {
                                "kendala" -> { chat = null; tab = "kendala"; issue = id }
                                "permintaan" -> { chat = null; tab = "permintaan" }
                            }
                        })
                    }
                    contacts -> {
                        BackHandler { contacts = false }
                        ContactsScreen(repo, onOpen = { contacts = false; chat = it }, onBack = { contacts = false })
                    }
                    chronoForm != null -> {
                        BackHandler { chronoForm = null }
                        ChronologyFormScreen(repo, chronoForm.takeIf { it != 0L }, onSaved = { chronoForm = null; chrono = it }, onBack = { chronoForm = null })
                    }
                    chrono != null -> {
                        BackHandler { chrono = null }
                        ChronologyScreen(repo, chrono!!, onEdit = { chronoForm = chrono }, onBack = { chrono = null })
                    }
                    chronoList -> {
                        BackHandler { chronoList = false }
                        ChronologyListScreen(repo, onOpen = { chrono = it }, onNew = { chronoForm = 0L }, onBack = { chronoList = false })
                    }
                    history -> {
                        BackHandler { history = false }
                        HistoryScreen(repo, onOpen = { open = it }, onBack = { history = false })
                    }
                    newIssue -> {
                        BackHandler { newIssue = false }
                        NewIssueScreen(repo, onCreated = { id -> newIssue = false; issue = id }, onBack = { newIssue = false })
                    }
                    newRequest -> {
                        BackHandler { newRequest = false }
                        NewRequestScreen(repo, onDone = { newRequest = false }, onBack = { newRequest = false })
                    }
                    issue != null -> {
                        BackHandler { issue = null }
                        IssueScreen(repo, issue!!, onBack = { issue = null })
                    }
                    else -> Column(Modifier.fillMaxSize()) {
                        // Kembali dari tab lain = ke tab Checklist dulu, baru keluar aplikasi.
                        BackHandler(enabled = tab != "checklist") { tab = "checklist" }
                        Box(Modifier.weight(1f)) {
                            when {
                                kendala && tab == "kendala" -> IssuesScreen(repo, onOpen = { issue = it }, onNew = { newIssue = true })
                                permintaan && tab == "permintaan" -> RequestsScreen(repo, onNew = { newRequest = true })
                                obrolan && tab == "obrolan" -> ChatListScreen(repo, onOpen = { chat = it }, onNew = { contacts = true })
                                papan && tab == "tim" -> BoardScreen(repo, onOpen = { open = it }, onHistory = { history = true }.takeIf { riwayat })
                                else -> ListScreen(repo, onOpen = { open = it }, onHistory = { history = true }.takeIf { riwayat }, onChronology = { chronoList = true }.takeIf { chronologyOn })
                            }
                        }
                        // Tab bawah hanya untuk menu yang fiturnya menyala dan boleh dipakai (dari /me).
                        if (kendala || permintaan || papan || obrolan) NavigationBar(containerColor = Color.White) {
                            NavigationBarItem(tab == "checklist", { tab = "checklist" }, icon = { Text("☑", fontSize = 20.sp) }, label = { Text("Checklist") })
                            if (papan) NavigationBarItem(tab == "tim", { tab = "tim" }, icon = { Text("👥", fontSize = 20.sp) }, label = { Text("Tim") })
                            if (kendala) NavigationBarItem(tab == "kendala", { tab = "kendala" }, icon = { Text("🛠", fontSize = 20.sp) }, label = { Text("Kendala") })
                            if (permintaan) NavigationBarItem(tab == "permintaan", { tab = "permintaan" }, icon = { Text("🛎", fontSize = 20.sp) }, label = { Text("Permintaan") })
                            if (obrolan) NavigationBarItem(tab == "obrolan", { tab = "obrolan" }, label = { Text("Obrolan") }, icon = {
                                BadgedBox(badge = { if (chatUnread.intValue > 0) Badge { Text("${chatUnread.intValue}") } }) { Text("💬", fontSize = 20.sp) }
                            })
                        }
                    }
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
fun ListScreen(repo: Repo, onOpen: (Long) -> Unit, onHistory: (() -> Unit)? = null, onChronology: (() -> Unit)? = null) {
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
                actions = {
                    // Menu lain: Riwayat checklist, Chronology Report, Keluar.
                    var menu by remember { mutableStateOf(false) }
                    TextButton(onClick = { menu = true }) { Text("⋮", color = Color.White, fontSize = 22.sp) }
                    androidx.compose.material3.DropdownMenu(menu, { menu = false }) {
                        onHistory?.let { androidx.compose.material3.DropdownMenuItem({ Text("Riwayat checklist") }, { menu = false; it() }) }
                        onChronology?.let { androidx.compose.material3.DropdownMenuItem({ Text("Chronology Report") }, { menu = false; it() }) }
                        androidx.compose.material3.DropdownMenuItem({ Text("Keluar", color = Red) }, { menu = false; confirmLogout = true })
                    }
                },
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
