package id.my.elhotelresortsanur.checklist

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material.icons.filled.Done
import androidx.compose.material.icons.filled.KeyboardArrowDown
import androidx.compose.material.icons.filled.Search
import androidx.compose.material.icons.automirrored.filled.Send
import androidx.compose.material3.*
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.painter.Painter
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.FileProvider
import androidx.lifecycle.compose.LifecycleResumeEffect
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.io.File
import java.time.LocalDate
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale

/*
 * Obrolan pekerjaan bergaya WhatsApp: daftar dengan avatar / centang / angka belum dibaca dan pencarian, percakapan dengan gelembung
 * bertali, pemisah tanggal, jam + centang di dalam gelembung, balas / salin / hapus (tekan lama), "sedang mengetik…", foto.
 * Tanpa WebSocket: daftar dimuat ulang tiap 8 detik dan percakapan tiap 3 detik selama layar terbuka (sama dengan web).
 * Fitur lain (grup baru, sematan, dokumen, ketik bebas, form dari obrolan) tetap di web.
 */

private val WITA = ZoneId.of("Asia/Makassar")
private val ID_LOCALE = Locale("id", "ID")
private val CLOCK = DateTimeFormatter.ofPattern("HH:mm")
private val SHORT_DAY = DateTimeFormatter.ofPattern("dd/MM/yy")
private val LONG_DAY = DateTimeFormatter.ofPattern("EEEE, d MMMM yyyy", ID_LOCALE)
private val ChatWall = Color(0xFFEFE7DA)
private val MineBubble = Color(0xFFF7E8C4)
private val ReadBlue = Color(0xFF34B7F1)

private fun zoned(iso: String?) = iso?.let { runCatching { OffsetDateTime.parse(it).atZoneSameInstant(WITA) }.getOrNull() }
private fun clock(iso: String?) = zoned(iso)?.format(CLOCK) ?: ""

/** Waktu di daftar obrolan: jam (hari ini), "Kemarin", nama hari (minggu ini), atau tanggal. */
private fun listTime(iso: String?): String {
    val t = zoned(iso) ?: return ""
    val days = java.time.temporal.ChronoUnit.DAYS.between(t.toLocalDate(), LocalDate.now(WITA))
    return when {
        days == 0L -> t.format(CLOCK)
        days == 1L -> "Kemarin"
        days < 7 -> t.format(DateTimeFormatter.ofPattern("EEEE", ID_LOCALE))
        else -> t.format(SHORT_DAY)
    }
}

private fun dayLabel(day: LocalDate): String = when (java.time.temporal.ChronoUnit.DAYS.between(day, LocalDate.now(WITA))) {
    0L -> "Hari ini"
    1L -> "Kemarin"
    else -> day.format(LONG_DAY)
}

/** Angka belum dibaca untuk tab bawah (diperbarui berkala oleh MainActivity, daftar obrolan, dan notifikasi yang masuk). */
val chatUnread = mutableIntStateOf(0)

/** Ambil angka belum dibaca dari server (diam bila gagal: tidak mengganggu dengan pesan galat tiap 10 detik). */
suspend fun refreshChatUnread(repo: Repo) = withContext(Dispatchers.IO) {
    runCatching { repo.api.get("/obrolan/belum-dibaca").optInt("unread") }.getOrNull()?.let { chatUnread.intValue = it }
}

// Warna avatar inisial (tetap per nama, seperti WhatsApp).
private val AvatarColors = listOf(0xFFB08A3E, 0xFF5B8A72, 0xFF7A6FA8, 0xFFB5654D, 0xFF4F7FA3, 0xFF9A6A8E, 0xFF6E8B3D, 0xFFA77B4F).map { Color(it) }

@Composable
fun Avatar(repo: Repo, name: String, initials: String?, photo: String?, size: Dp, group: Boolean = false) {
    val color = AvatarColors[(name.hashCode() and 0x7fffffff) % AvatarColors.size]
    Box(Modifier.size(size).clip(CircleShape).background(color.copy(alpha = 0.18f)), contentAlignment = Alignment.Center) {
        when {
            photo != null -> RemoteImage("avatar-$photo", Modifier.fillMaxSize(), placeholder = "") { Photos.thumbnail(repo.api.bytes(photo), 192) }
            group -> Icon(painterResource(R.drawable.ic_group), null, tint = color, modifier = Modifier.size(size * 0.55f))
            else -> Text(
                (initials ?: name.split(" ").filter { it.isNotBlank() }.take(2).joinToString("") { it.take(1) }).uppercase(),
                color = color, fontWeight = FontWeight.Bold, fontSize = (size.value * 0.36f).sp,
            )
        }
    }
}

@Composable
private fun Ticks(read: Boolean, size: Dp = 16.dp, tint: Color = Muted) =
    Icon(if (read) painterResource(R.drawable.ic_done_all) else rememberVectorDone(), null, tint = if (read) ReadBlue else tint, modifier = Modifier.size(size))

@Composable
private fun rememberVectorDone(): Painter = androidx.compose.ui.graphics.vector.rememberVectorPainter(Icons.Filled.Done)

// ---- Daftar obrolan ----

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ChatListScreen(repo: Repo, onOpen: (Long) -> Unit, onNew: () -> Unit) {
    var threads by remember { mutableStateOf<List<JSONObject>?>(null) }
    var refreshing by remember { mutableStateOf(false) }
    var searching by remember { mutableStateOf(false) }
    var query by remember { mutableStateOf("") }
    val scope = rememberCoroutineScope()

    suspend fun load() {
        repo.call { it.get("/obrolan") }?.let { threads = it.optJSONArray("threads").objects(); chatUnread.intValue = it.optInt("unread") }
    }
    LifecycleResumeEffect(Unit) {
        val job = scope.launch { while (isActive) { load(); delay(8_000) } }
        onPauseOrDispose { job.cancel() }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = {
                    if (searching) SearchField(query, { query = it }, "Cari obrolan…")
                    else Text("Obrolan", fontWeight = FontWeight.SemiBold)
                },
                navigationIcon = { if (searching) IconButton({ searching = false; query = "" }) { Icon(Icons.AutoMirrored.Filled.ArrowBack, "Tutup cari", tint = Color.White) } },
                actions = { if (!searching) IconButton({ searching = true }) { Icon(Icons.Filled.Search, "Cari", tint = Color.White) } },
                colors = TopAppBarDefaults.topAppBarColors(containerColor = Ink, titleContentColor = Color.White),
            )
        },
        floatingActionButton = {
            FloatingActionButton(onClick = onNew, containerColor = Gold, contentColor = Color.White, shape = RoundedCornerShape(16.dp)) {
                Icon(painterResource(R.drawable.ic_chat), "Chat baru")
            }
        },
        containerColor = Color.White,
    ) { padding ->
        Column(Modifier.padding(padding)) {
            Banner(repo)
            NotificationsOffBanner()
            val shown = threads.orEmpty().filter { query.isBlank() || it.optString("title").contains(query.trim(), true) || it.optString("last").contains(query.trim(), true) }
            PullToRefreshBox(isRefreshing = refreshing, onRefresh = { scope.launch { refreshing = true; load(); refreshing = false } }, modifier = Modifier.fillMaxSize()) {
                LazyColumn(contentPadding = PaddingValues(bottom = 96.dp), modifier = Modifier.fillMaxSize()) {
                    when {
                        threads == null -> item { Loading() }
                        threads!!.isEmpty() -> item { EmptyChats() }
                        shown.isEmpty() -> item { Text("Tidak ada obrolan yang cocok.", color = Muted, modifier = Modifier.padding(20.dp)) }
                    }
                    items(shown, key = { it.getLong("id") }) { t -> ThreadRow(repo, t) { onOpen(t.getLong("id")) } }
                }
            }
        }
    }
}

/** Notifikasi aplikasi dimatikan di HP (atau izin ditolak): beri tahu dan buka pengaturannya (staff sendiri yang menyalakan). */
@Composable
private fun NotificationsOffBanner() {
    val context = LocalContext.current
    var enabled by remember { mutableStateOf(true) }
    LifecycleResumeEffect(Unit) {
        enabled = androidx.core.app.NotificationManagerCompat.from(context).areNotificationsEnabled()
        onPauseOrDispose { }
    }
    if (enabled) return
    Row(Modifier.fillMaxWidth().background(Color(0xFFFDF3D8)).padding(start = 16.dp, end = 8.dp, top = 8.dp, bottom = 8.dp), verticalAlignment = Alignment.CenterVertically) {
        Text("Notifikasi aplikasi mati. Pesan baru tidak akan muncul di HP.", fontSize = 13.sp, color = Ink, modifier = Modifier.weight(1f))
        TextButton({
            context.startActivity(android.content.Intent(android.provider.Settings.ACTION_APP_NOTIFICATION_SETTINGS)
                .putExtra(android.provider.Settings.EXTRA_APP_PACKAGE, context.packageName).addFlags(android.content.Intent.FLAG_ACTIVITY_NEW_TASK))
        }) { Text("Aktifkan", fontWeight = FontWeight.SemiBold) }
    }
}

@Composable
private fun EmptyChats() = Column(Modifier.fillMaxWidth().padding(40.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(12.dp)) {
    Icon(painterResource(R.drawable.ic_chat), null, tint = GoldSoft, modifier = Modifier.size(72.dp))
    Text("Belum ada obrolan", fontWeight = FontWeight.SemiBold, color = Ink)
    Text("Ketuk tombol di kanan bawah untuk mulai chat dengan rekan kerja.", color = Muted, fontSize = 14.sp, textAlign = androidx.compose.ui.text.style.TextAlign.Center)
}

@Composable
private fun ThreadRow(repo: Repo, t: JSONObject, onClick: () -> Unit) {
    val unread = t.optInt("unread")
    Row(Modifier.fillMaxWidth().clickable(onClick = onClick).padding(horizontal = 16.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
        Avatar(repo, t.optString("title"), t.s("initials"), t.s("photo"), 52.dp, group = t.optBoolean("group"))
        Spacer(Modifier.width(14.dp))
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(t.optString("title"), fontWeight = FontWeight.SemiBold, fontSize = 16.sp, color = Ink, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
                Text(listTime(t.s("last_at")), fontSize = 12.sp, color = if (unread > 0) Gold else Muted, fontWeight = if (unread > 0) FontWeight.SemiBold else FontWeight.Normal)
            }
            Row(verticalAlignment = Alignment.CenterVertically) {
                if (t.optBoolean("last_mine")) { Ticks(t.optBoolean("last_read")); Spacer(Modifier.width(3.dp)) }
                Text(t.optString("last"), fontSize = 14.sp, color = Muted, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
                if (unread > 0) Box(Modifier.padding(start = 8.dp).defaultMinSize(minWidth = 22.dp, minHeight = 22.dp).background(Gold, CircleShape).padding(horizontal = 6.dp), contentAlignment = Alignment.Center) {
                    Text(if (unread > 99) "99+" else "$unread", color = Color.White, fontSize = 12.sp, fontWeight = FontWeight.Bold)
                }
            }
        }
    }
    HorizontalDivider(Modifier.padding(start = 82.dp), color = Color(0xFFF0EBE3))
}

@Composable
private fun SearchField(value: String, onChange: (String) -> Unit, hint: String) {
    val focus = remember { androidx.compose.ui.focus.FocusRequester() }
    LaunchedEffect(Unit) { focus.requestFocus() }
    BasicTextField(
        value, onChange, singleLine = true, cursorBrush = SolidColor(Color.White),
        textStyle = TextStyle(color = Color.White, fontSize = 18.sp), modifier = Modifier.fillMaxWidth().focusRequester(focus),
        decorationBox = { inner -> Box { if (value.isEmpty()) Text(hint, color = Color(0xFFBFB3A3), fontSize = 18.sp); inner() } },
    )
}


// ---- Kontak (chat baru) ----

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ContactsScreen(repo: Repo, onOpen: (Long) -> Unit, onBack: () -> Unit) {
    val scope = rememberCoroutineScope()
    var people by remember { mutableStateOf<List<JSONObject>?>(null) }
    var query by remember { mutableStateOf("") }
    var searching by remember { mutableStateOf(false) }
    var opening by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) { people = repo.call { it.get("/obrolan/kontak") }?.optJSONArray("people").objects() }

    Scaffold(
        topBar = {
            TopAppBar(
                title = {
                    if (searching) SearchField(query, { query = it }, "Cari nama…")
                    else Column {
                        Text("Pilih kontak", fontWeight = FontWeight.SemiBold)
                        people?.let { Text("${it.size} kontak", fontSize = 12.sp, color = Color(0xFFDCCDB6)) }
                    }
                },
                navigationIcon = { IconButton(onClick = { if (searching) { searching = false; query = "" } else onBack() }) { Icon(Icons.AutoMirrored.Filled.ArrowBack, "Kembali", tint = Color.White) } },
                actions = { if (!searching) IconButton({ searching = true }) { Icon(Icons.Filled.Search, "Cari", tint = Color.White) } },
                colors = TopAppBarDefaults.topAppBarColors(containerColor = Ink, titleContentColor = Color.White),
            )
        },
        containerColor = Color.White,
    ) { padding ->
        Column(Modifier.padding(padding)) {
            Banner(repo)
            val shown = people.orEmpty().filter { query.isBlank() || it.optString("name").contains(query.trim(), true) || it.optString("detail").contains(query.trim(), true) }
            LazyColumn {
                if (people == null) item { Loading() }
                else item { Text("Kontak di aplikasi ini", color = Gold, fontSize = 13.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(start = 16.dp, top = 14.dp, bottom = 6.dp)) }
                items(shown, key = { it.getLong("id") }) { p ->
                    Row(
                        Modifier.fillMaxWidth().clickable(enabled = !opening) {
                            opening = true
                            scope.launch { repo.call { it.send("POST", "/obrolan/pribadi/${p.getLong("id")}") }?.let { res -> onOpen(res.getLong("id")) }; opening = false }
                        }.padding(horizontal = 16.dp, vertical = 10.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Avatar(repo, p.optString("name"), null, p.s("photo"), 46.dp)
                        Spacer(Modifier.width(14.dp))
                        Column(Modifier.weight(1f)) {
                            Text(p.optString("name"), fontWeight = FontWeight.SemiBold, color = Ink, fontSize = 16.sp)
                            p.s("detail")?.let { Text(it, fontSize = 13.sp, color = Muted, maxLines = 1, overflow = TextOverflow.Ellipsis) }
                        }
                    }
                }
            }
        }
    }
}

// ---- Percakapan ----

private data class Row2(val message: JSONObject, val day: LocalDate?, val firstOfDay: Boolean, val firstOfRun: Boolean)

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ChatScreen(repo: Repo, threadId: Long, onBack: () -> Unit, onCard: (String, Long) -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val messages = remember { mutableStateListOf<JSONObject>() }
    var info by remember { mutableStateOf<JSONObject?>(null) }
    var typing by remember { mutableStateOf<List<String>>(emptyList()) }
    var readUpto by remember { mutableLongStateOf(0) }
    var text by remember { mutableStateOf("") }
    var replyTo by remember { mutableStateOf<JSONObject?>(null) }
    var sending by remember { mutableStateOf(false) }
    var viewing by remember { mutableStateOf<Long?>(null) }
    var deleting by remember { mutableStateOf<JSONObject?>(null) }
    var attach by remember { mutableStateOf(false) }
    var lastTypingPing by remember { mutableLongStateOf(0) }
    val list = rememberLazyListState()
    val cameraFile = remember { File(context.cacheDir, "camera/chat.jpg").apply { parentFile?.mkdirs() } }

    suspend fun poll() {
        val after = messages.lastOrNull()?.getLong("id") ?: 0
        val res = repo.call { it.get("/obrolan/$threadId/pesan?after=$after") } ?: return
        info = res.optJSONObject("thread")
        readUpto = res.optLong("read_upto")
        typing = res.optJSONArray("typing")?.let { a -> (0 until a.length()).map { a.getString(it) } }.orEmpty()
        val deleted = res.optJSONArray("deleted")?.let { a -> (0 until a.length()).map { a.getLong(it) }.toSet() }.orEmpty()
        if (deleted.isNotEmpty()) messages.replaceAll { m -> if (m.getLong("id") in deleted) JSONObject(m.toString()).put("deleted", true).put("body", JSONObject.NULL).put("photo", false).put("card", JSONObject.NULL) else m }
        val fresh = res.optJSONArray("messages").objects().filter { m -> messages.none { it.getLong("id") == m.getLong("id") } }
        if (fresh.isNotEmpty()) {
            val atBottom = list.firstVisibleItemIndex <= 1
            messages.addAll(fresh)
            if (atBottom || fresh.any { it.optBoolean("mine") }) list.animateScrollToItem(0)
        }
    }
    LifecycleResumeEffect(threadId) {
        val job = scope.launch { while (isActive) { poll(); delay(3_000) } }
        onPauseOrDispose { job.cancel() }
    }

    fun send(photo: File? = null) {
        val body = text.trim()
        if ((body.isEmpty() && photo == null) || sending) return
        sending = true
        val reply = replyTo?.getLong("id")
        scope.launch {
            try {
                withContext(Dispatchers.IO) {
                    val fields = buildMap { if (body.isNotEmpty()) put("body", body); reply?.let { put("reply_to", it.toString()) } }
                    if (photo != null) repo.api.multipart("/obrolan/$threadId/pesan", fields, listOf("photo" to photo))
                    else repo.api.send("POST", "/obrolan/$threadId/pesan", JSONObject(fields))
                }
                text = ""; replyTo = null
                photo?.delete()
                poll()
            } catch (e: ApiException) {
                if (e.code == 401) repo.sessionEnded() else repo.message.value = if (e.code == 0) "Tidak ada koneksi. Pesan belum terkirim." else e.message
            } finally {
                sending = false
            }
        }
    }
    fun sendPhoto(uri: Uri) = scope.launch {
        val out = File(context.cacheDir, "camera/chat-out-${System.nanoTime()}.jpg")
        if (withContext(Dispatchers.IO) { Photos.compress(context, uri, out) }) send(out) else repo.message.value = "Foto tidak bisa dibaca."
    }
    val camera = rememberLauncherForActivityResult(ActivityResultContracts.TakePicture()) { ok -> if (ok) sendPhoto(Uri.fromFile(cameraFile)) }
    val gallery = rememberLauncherForActivityResult(ActivityResultContracts.PickVisualMedia()) { it?.let(::sendPhoto) }

    // Baris tampilan: pemisah tanggal + pengelompokan pesan beruntun dari pengirim yang sama (nama & tali hanya di yang pertama).
    val rows = remember(messages.toList()) {
        var lastDay: LocalDate? = null
        var lastSender: Long? = null
        messages.map { m ->
            val day = zoned(m.s("at"))?.toLocalDate()
            val firstOfDay = day != lastDay
            val sender = if (m.optBoolean("system")) -1L else m.optLong("user_id")
            val firstOfRun = firstOfDay || sender != lastSender
            lastDay = day; lastSender = sender
            Row2(m, day, firstOfDay, firstOfRun)
        }.asReversed()
    }
    val group = info?.optBoolean("group") == true

    Scaffold(
        topBar = {
            TopAppBar(
                title = {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Avatar(repo, info?.optString("title") ?: "", info?.s("initials"), info?.s("photo"), 38.dp, group = group)
                        Spacer(Modifier.width(10.dp))
                        Column {
                            Text(info?.optString("title") ?: "", fontWeight = FontWeight.SemiBold, fontSize = 17.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                            val sub = when {
                                typing.isNotEmpty() -> if (group) "${typing.first().substringBefore(' ')} sedang mengetik…" else "sedang mengetik…"
                                else -> info?.s("subtitle")
                            }
                            sub?.let { Text(it, fontSize = 12.sp, color = if (typing.isNotEmpty()) Color(0xFFE9C77B) else Color(0xFFDCCDB6), maxLines = 1, overflow = TextOverflow.Ellipsis) }
                        }
                    }
                },
                navigationIcon = { IconButton(onClick = onBack) { Icon(Icons.AutoMirrored.Filled.ArrowBack, "Kembali", tint = Color.White) } },
                colors = TopAppBarDefaults.topAppBarColors(containerColor = Ink, titleContentColor = Color.White),
            )
        },
        containerColor = ChatWall,
    ) { padding ->
        Column(Modifier.padding(padding).imePadding()) {
            Banner(repo)
            Box(Modifier.weight(1f).fillMaxWidth()) {
                LazyColumn(state = list, reverseLayout = true, modifier = Modifier.fillMaxSize(), contentPadding = PaddingValues(horizontal = 10.dp, vertical = 8.dp)) {
                    itemsIndexed(rows, key = { _, r -> r.message.getLong("id") }) { _, r ->
                        Column {
                            if (r.firstOfDay && r.day != null) DayChip(dayLabel(r.day))
                            Bubble(repo, threadId, r, group, readUpto,
                                onPhoto = { viewing = it }, onCard = onCard,
                                onReply = { replyTo = it }, onDelete = { deleting = it },
                                onCopy = { body ->
                                    (context.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager).setPrimaryClip(ClipData.newPlainText("Pesan", body))
                                    android.widget.Toast.makeText(context, "Pesan disalin", android.widget.Toast.LENGTH_SHORT).show()
                                })
                        }
                    }
                    if (info != null && messages.isEmpty()) item { DayChip("Mulai obrolan. Pesan hanya terlihat oleh peserta.") }
                }
                // Tombol ke pesan terbaru (seperti WhatsApp) saat sedang membaca pesan lama.
                if (list.firstVisibleItemIndex > 3) SmallFloatingActionButton(
                    onClick = { scope.launch { list.animateScrollToItem(0) } }, containerColor = Color.White, contentColor = Muted, shape = CircleShape,
                    modifier = Modifier.align(Alignment.BottomEnd).padding(12.dp),
                ) { Icon(Icons.Filled.KeyboardArrowDown, "Ke pesan terbaru") }
            }

            if (info?.optBoolean("can_post", true) == false) {
                Text("Anda hanya memantau obrolan ini.", color = Muted, fontSize = 14.sp, modifier = Modifier.fillMaxWidth().background(Color.White).padding(16.dp))
            } else Row(Modifier.fillMaxWidth().padding(horizontal = 6.dp, vertical = 6.dp), verticalAlignment = Alignment.Bottom) {
                Column(Modifier.weight(1f).background(Color.White, RoundedCornerShape(24.dp))) {
                    replyTo?.let { r ->
                        Row(Modifier.padding(start = 6.dp, end = 6.dp, top = 6.dp).fillMaxWidth().clip(RoundedCornerShape(10.dp)).background(Color(0xFFF3EEE6)), verticalAlignment = Alignment.CenterVertically) {
                            Box(Modifier.width(4.dp).height(46.dp).background(Gold))
                            Column(Modifier.weight(1f).padding(horizontal = 8.dp, vertical = 4.dp)) {
                                Text(if (r.optBoolean("mine")) "Anda" else r.optString("user"), color = Gold, fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
                                Text(r.s("body") ?: if (r.optBoolean("photo")) "📷 Foto" else r.optJSONObject("card")?.optString("text") ?: "", fontSize = 13.sp, color = Muted, maxLines = 1, overflow = TextOverflow.Ellipsis)
                            }
                            IconButton({ replyTo = null }, Modifier.size(32.dp)) { Icon(Icons.Filled.Close, "Batal balas", tint = Muted, modifier = Modifier.size(18.dp)) }
                        }
                    }
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        BasicTextField(
                            text, { value ->
                                text = value.take(4000)
                                // "sedang mengetik…" untuk lawan bicara, paling sering tiap 4 detik.
                                val now = System.currentTimeMillis()
                                if (value.isNotBlank() && now - lastTypingPing > 4_000) {
                                    lastTypingPing = now
                                    scope.launch(Dispatchers.IO) { runCatching { repo.api.send("POST", "/obrolan/$threadId/mengetik") } }
                                }
                            },
                            textStyle = TextStyle(fontSize = 17.sp, color = Ink), cursorBrush = SolidColor(Gold), maxLines = 6,
                            keyboardOptions = KeyboardOptions(capitalization = KeyboardCapitalization.Sentences),
                            modifier = Modifier.weight(1f).padding(start = 18.dp, top = 12.dp, bottom = 12.dp),
                            decorationBox = { inner -> Box { if (text.isEmpty()) Text("Pesan", color = Color(0xFFA79C90), fontSize = 17.sp); inner() } },
                        )
                        Box {
                            IconButton({ attach = true }, enabled = !sending) { Icon(painterResource(R.drawable.ic_attach), "Lampiran", tint = Muted) }
                            DropdownMenu(attach, { attach = false }) {
                                DropdownMenuItem({ Text("Galeri") }, { attach = false; gallery.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly)) },
                                    leadingIcon = { Icon(painterResource(R.drawable.ic_image), null, tint = Color(0xFF7A6FA8)) })
                                DropdownMenuItem({ Text("Kamera") }, { attach = false; camera.launch(FileProvider.getUriForFile(context, context.packageName + ".files", cameraFile)) },
                                    leadingIcon = { Icon(painterResource(R.drawable.ic_camera), null, tint = Color(0xFFB5654D)) })
                            }
                        }
                        if (text.isEmpty()) IconButton({ camera.launch(FileProvider.getUriForFile(context, context.packageName + ".files", cameraFile)) }, enabled = !sending) {
                            Icon(painterResource(R.drawable.ic_camera), "Kamera", tint = Muted)
                        }
                    }
                }
                Spacer(Modifier.width(6.dp))
                Box(
                    Modifier.size(50.dp).clip(CircleShape).background(if (text.isNotBlank()) Gold else Gold.copy(alpha = 0.55f))
                        .clickable(enabled = text.isNotBlank() && !sending) { send() },
                    contentAlignment = Alignment.Center,
                ) {
                    if (sending) CircularProgressIndicator(Modifier.size(22.dp), color = Color.White, strokeWidth = 2.dp)
                    else Icon(Icons.AutoMirrored.Filled.Send, "Kirim", tint = Color.White, modifier = Modifier.padding(start = 3.dp))
                }
            }
        }
    }

    viewing?.let { id ->
        androidx.compose.ui.window.Dialog(onDismissRequest = { viewing = null }, properties = androidx.compose.ui.window.DialogProperties(usePlatformDefaultWidth = false)) {
            Box(Modifier.fillMaxSize().background(Color.Black).clickable { viewing = null }, contentAlignment = Alignment.Center) {
                RemoteImage("chat-$id@1280", Modifier.fillMaxSize(), crop = false) { Photos.thumbnail(repo.api.bytes("/obrolan/$threadId/foto/$id"), 1280) }
                IconButton({ viewing = null }, Modifier.align(Alignment.TopStart).padding(8.dp)) { Icon(Icons.AutoMirrored.Filled.ArrowBack, "Tutup", tint = Color.White) }
            }
        }
    }
    deleting?.let { m ->
        AlertDialog(
            onDismissRequest = { deleting = null },
            text = { Text("Hapus pesan ini untuk semua orang?") },
            confirmButton = {
                TextButton({
                    deleting = null
                    scope.launch {
                        repo.call { it.send("DELETE", "/obrolan/$threadId/pesan/${m.getLong("id")}") }
                        messages.replaceAll { x -> if (x.getLong("id") == m.getLong("id")) JSONObject(x.toString()).put("deleted", true).put("body", JSONObject.NULL).put("photo", false).put("card", JSONObject.NULL) else x }
                    }
                }) { Text("Hapus untuk semua", color = Red) }
            },
            dismissButton = { TextButton({ deleting = null }) { Text("Batal") } },
        )
    }
}

@Composable
private fun DayChip(text: String) = Box(Modifier.fillMaxWidth().padding(vertical = 8.dp), contentAlignment = Alignment.Center) {
    Text(text, fontSize = 12.sp, color = Color(0xFF6B6157), modifier = Modifier.background(Color(0xFFFFF8EC), RoundedCornerShape(8.dp)).padding(horizontal = 12.dp, vertical = 5.dp))
}

@OptIn(ExperimentalFoundationApi::class)
@Composable
private fun Bubble(
    repo: Repo, threadId: Long, r: Row2, group: Boolean, readUpto: Long,
    onPhoto: (Long) -> Unit, onCard: (String, Long) -> Unit, onReply: (JSONObject) -> Unit, onDelete: (JSONObject) -> Unit, onCopy: (String) -> Unit,
) {
    val m = r.message
    val id = m.getLong("id")
    if (m.optBoolean("system")) {
        DayChip(m.optString("body"))
        return
    }
    val mine = m.optBoolean("mine")
    val deleted = m.optBoolean("deleted")
    var menu by remember { mutableStateOf(false) }
    // Tali gelembung hanya di pesan pertama dari rangkaian pengirim yang sama (seperti WhatsApp).
    val shape = when {
        !r.firstOfRun -> RoundedCornerShape(10.dp)
        mine -> RoundedCornerShape(topStart = 10.dp, topEnd = 2.dp, bottomEnd = 10.dp, bottomStart = 10.dp)
        else -> RoundedCornerShape(topStart = 2.dp, topEnd = 10.dp, bottomEnd = 10.dp, bottomStart = 10.dp)
    }
    val time: @Composable () -> Unit = {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(clock(m.s("at")), fontSize = 11.sp, color = Color(0xFF8C8177))
            if (mine && !deleted) { Spacer(Modifier.width(3.dp)); Ticks(id <= readUpto, 15.dp) }
        }
    }

    Box(Modifier.fillMaxWidth().padding(top = if (r.firstOfRun) 6.dp else 1.dp), contentAlignment = if (mine) Alignment.CenterEnd else Alignment.CenterStart) {
        Box {
            Column(
                Modifier.widthIn(min = 80.dp, max = 300.dp).clip(shape).background(if (mine) MineBubble else Color.White)
                    .combinedClickable(onClick = { if (m.optBoolean("photo")) onPhoto(id) }, onLongClick = { if (!deleted) menu = true })
                    .padding(start = 8.dp, end = 8.dp, top = 5.dp, bottom = 4.dp),
            ) {
                if (group && !mine && r.firstOfRun) {
                    val color = AvatarColors[(m.optString("user").hashCode() and 0x7fffffff) % AvatarColors.size]
                    Text(m.optString("user"), fontSize = 13.sp, fontWeight = FontWeight.SemiBold, color = color, modifier = Modifier.padding(bottom = 2.dp))
                }
                m.optJSONObject("reply")?.let { q ->
                    Row(Modifier.padding(bottom = 4.dp).fillMaxWidth().clip(RoundedCornerShape(8.dp)).background(Color.Black.copy(alpha = 0.05f)).height(IntrinsicSize.Min)) {
                        Box(Modifier.width(4.dp).fillMaxHeight().background(Gold))
                        Column(Modifier.padding(horizontal = 8.dp, vertical = 4.dp)) {
                            Text(q.optString("user"), fontSize = 12.sp, fontWeight = FontWeight.SemiBold, color = Gold)
                            Text(q.optString("text"), fontSize = 13.sp, color = Muted, maxLines = 2, overflow = TextOverflow.Ellipsis)
                        }
                    }
                }
                when {
                    deleted -> Row(verticalAlignment = Alignment.Bottom) {
                        Text("🚫 Pesan ini dihapus", fontSize = 15.sp, color = Color(0xFF8C8177), fontStyle = androidx.compose.ui.text.font.FontStyle.Italic)
                        Spacer(Modifier.width(8.dp)); time()
                    }
                    else -> {
                        if (m.optBoolean("photo")) Box(Modifier.padding(bottom = 4.dp).size(240.dp).clip(RoundedCornerShape(8.dp))) {
                            RemoteImage("chat-$id@512", Modifier.fillMaxSize()) { Photos.thumbnail(repo.api.bytes("/obrolan/$threadId/foto/$id"), 512) }
                        }
                        m.optJSONObject("card")?.let { c ->
                            val type = c.optString("type")
                            val opens = type == "kendala" || type == "permintaan"
                            Text(c.optString("text"), fontSize = 14.sp, fontWeight = FontWeight.SemiBold, color = if (opens) Gold else Ink,
                                modifier = Modifier.padding(bottom = 4.dp).fillMaxWidth().clip(RoundedCornerShape(8.dp)).background(Color.Black.copy(alpha = 0.04f))
                                    .clickable(enabled = opens) { onCard(type, c.getLong("id")) }.padding(8.dp))
                        }
                        m.s("file")?.let { Text("📄 $it\n(buka di web)", fontSize = 14.sp, color = Ink, modifier = Modifier.padding(bottom = 4.dp)) }
                        val body = m.s("body")
                        if (body != null) {
                            // Jam + centang di ujung baris terakhir: ruang kosong di akhir teks, jam ditaruh di pojok kanan bawah.
                            Box {
                                Text(body + if (mine) " ".repeat(16) else " ".repeat(11), fontSize = 16.sp, color = Ink, lineHeight = 21.sp)
                                Box(Modifier.align(Alignment.BottomEnd)) { time() }
                            }
                        } else Box(Modifier.align(Alignment.End)) { time() }
                    }
                }
            }
            DropdownMenu(menu, { menu = false }) {
                DropdownMenuItem({ Text("Balas") }, { menu = false; onReply(m) }, leadingIcon = { Icon(painterResource(R.drawable.ic_reply), null) })
                m.s("body")?.let { body -> DropdownMenuItem({ Text("Salin") }, { menu = false; onCopy(body) }, leadingIcon = { Icon(painterResource(R.drawable.ic_copy), null) }) }
                if (mine) DropdownMenuItem({ Text("Hapus", color = Red) }, { menu = false; onDelete(m) }, leadingIcon = { Icon(Icons.Filled.Delete, null, tint = Red) })
            }
        }
    }
}
