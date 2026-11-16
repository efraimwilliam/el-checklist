package id.my.elhotelresortsanur.checklist

import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.*
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.FileProvider
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LifecycleResumeEffect
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.io.File
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter

/*
 * Obrolan pekerjaan (seperti web /obrolan): daftar obrolan, percakapan teks + foto, chat pribadi baru.
 * Tanpa WebSocket: daftar dimuat ulang tiap 8 detik dan percakapan tiap 3 detik selama layar terbuka (sama dengan web).
 * Fitur lain (grup baru, sematan, dokumen, ketik bebas, form dari obrolan) tetap di web.
 */

private val CLOCK = DateTimeFormatter.ofPattern("HH:mm")
private val DAY = DateTimeFormatter.ofPattern("d MMM", java.util.Locale("id", "ID"))
private fun shortTime(iso: String?): String = iso?.let {
    runCatching {
        val t = OffsetDateTime.parse(it).atZoneSameInstant(ZoneId.of("Asia/Makassar"))
        if (t.toLocalDate() == java.time.LocalDate.now(ZoneId.of("Asia/Makassar"))) t.format(CLOCK) else t.format(DAY)
    }.getOrNull()
} ?: ""

/** Angka belum dibaca untuk tab bawah (diperbarui daftar obrolan dan saat aplikasi dibuka). */
val chatUnread = mutableIntStateOf(0)

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ChatListScreen(repo: Repo, onOpen: (Long) -> Unit, onNew: () -> Unit) {
    var threads by remember { mutableStateOf<List<JSONObject>?>(null) }
    var refreshing by remember { mutableStateOf(false) }
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
            TopAppBar(title = { Text("Obrolan", fontWeight = FontWeight.SemiBold) },
                colors = TopAppBarDefaults.topAppBarColors(containerColor = Ink, titleContentColor = Color.White))
        },
        floatingActionButton = { ExtendedFloatingActionButton(onClick = onNew, containerColor = Ink, contentColor = Color.White) { Text("+ Chat baru") } },
        containerColor = Cream,
    ) { padding ->
        Column(Modifier.padding(padding)) {
            Banner(repo)
            PullToRefreshBox(isRefreshing = refreshing, onRefresh = { scope.launch { refreshing = true; load(); refreshing = false } }, modifier = Modifier.fillMaxSize()) {
                LazyColumn(contentPadding = PaddingValues(bottom = 96.dp), modifier = Modifier.fillMaxSize()) {
                    when {
                        threads == null -> item { Text("Memuat…", color = Muted, modifier = Modifier.padding(16.dp)) }
                        threads!!.isEmpty() -> item { Text("Belum ada obrolan. Ketuk \"+ Chat baru\" untuk mulai.", color = Muted, modifier = Modifier.padding(16.dp)) }
                    }
                    items(threads.orEmpty(), key = { it.getLong("id") }) { t ->
                        val unread = t.optInt("unread")
                        Row(Modifier.fillMaxWidth().background(Color.White).clickable { onOpen(t.getLong("id")) }.padding(horizontal = 16.dp, vertical = 12.dp), verticalAlignment = Alignment.CenterVertically) {
                            Box(Modifier.size(44.dp).background(GoldSoft, CircleShape), contentAlignment = Alignment.Center) { Text(t.optString("initials"), color = Gold, fontWeight = FontWeight.Bold) }
                            Spacer(Modifier.width(12.dp))
                            Column(Modifier.weight(1f)) {
                                Row {
                                    Text(t.optString("title"), fontWeight = FontWeight.SemiBold, color = Ink, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
                                    Text(shortTime(t.s("last_at")), fontSize = 12.sp, color = if (unread > 0) Green else Muted)
                                }
                                Row(verticalAlignment = Alignment.CenterVertically) {
                                    Text(t.optString("last"), fontSize = 14.sp, color = Muted, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
                                    if (unread > 0) Text("$unread", color = Color.White, fontSize = 12.sp, fontWeight = FontWeight.Bold,
                                        modifier = Modifier.background(Green, CircleShape).padding(horizontal = 7.dp, vertical = 2.dp))
                                }
                            }
                        }
                        HorizontalDivider(color = Cream)
                    }
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ContactsScreen(repo: Repo, onOpen: (Long) -> Unit, onBack: () -> Unit) {
    val scope = rememberCoroutineScope()
    var people by remember { mutableStateOf<List<JSONObject>?>(null) }
    var query by remember { mutableStateOf("") }
    LaunchedEffect(Unit) { people = repo.call { it.get("/obrolan/kontak") }?.optJSONArray("people").objects() }

    Scaffold(
        topBar = {
            TopAppBar(title = { Text("Chat baru", fontWeight = FontWeight.SemiBold) },
                navigationIcon = { TextButton(onClick = onBack) { Text("‹ Kembali", color = Color.White) } },
                colors = TopAppBarDefaults.topAppBarColors(containerColor = Ink, titleContentColor = Color.White))
        },
        containerColor = Cream,
    ) { padding ->
        Column(Modifier.padding(padding)) {
            Banner(repo)
            OutlinedTextField(query, { query = it }, label = { Text("Cari nama") }, singleLine = true, modifier = Modifier.fillMaxWidth().padding(16.dp))
            val shown = people.orEmpty().filter { query.isBlank() || it.optString("name").contains(query.trim(), true) || it.optString("detail").contains(query.trim(), true) }
            LazyColumn {
                if (people == null) item { Text("Memuat…", color = Muted, modifier = Modifier.padding(16.dp)) }
                items(shown, key = { it.getLong("id") }) { p ->
                    Column(Modifier.fillMaxWidth().background(Color.White).clickable {
                        scope.launch { repo.call { it.send("POST", "/obrolan/pribadi/${p.getLong("id")}") }?.let { res -> onOpen(res.getLong("id")) } }
                    }.padding(horizontal = 16.dp, vertical = 12.dp)) {
                        Text(p.optString("name"), fontWeight = FontWeight.SemiBold, color = Ink)
                        p.s("detail")?.let { Text(it, fontSize = 13.sp, color = Muted) }
                    }
                    HorizontalDivider(color = Cream)
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class, ExperimentalFoundationApi::class)
@Composable
fun ChatScreen(repo: Repo, threadId: Long, onBack: () -> Unit, onCard: (String, Long) -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val messages = remember { mutableStateListOf<JSONObject>() }
    var info by remember { mutableStateOf<JSONObject?>(null) }
    var readUpto by remember { mutableLongStateOf(0) }
    var text by remember { mutableStateOf("") }
    var sending by remember { mutableStateOf(false) }
    var viewing by remember { mutableStateOf<Long?>(null) }
    var deleting by remember { mutableStateOf<JSONObject?>(null) }
    val list = rememberLazyListState()
    val cameraFile = remember { File(context.cacheDir, "camera/chat.jpg").apply { parentFile?.mkdirs() } }

    suspend fun poll() {
        val after = messages.lastOrNull()?.getLong("id") ?: 0
        val res = repo.call { it.get("/obrolan/$threadId/pesan?after=$after") } ?: return
        info = res.optJSONObject("thread")
        readUpto = res.optLong("read_upto")
        val deleted = res.optJSONArray("deleted")?.let { a -> (0 until a.length()).map { a.getLong(it) }.toSet() }.orEmpty()
        if (deleted.isNotEmpty()) messages.replaceAll { m -> if (m.getLong("id") in deleted) JSONObject(m.toString()).put("deleted", true).put("body", JSONObject.NULL).put("photo", false) else m }
        val fresh = res.optJSONArray("messages").objects().filter { m -> messages.none { it.getLong("id") == m.getLong("id") } }
        if (fresh.isNotEmpty()) {
            val atBottom = !list.canScrollForward
            messages.addAll(fresh)
            if (atBottom || after == 0L || fresh.any { it.optBoolean("mine") }) list.scrollToItem(messages.size - 1)
        }
    }
    LifecycleResumeEffect(threadId) {
        val job = scope.launch { while (isActive) { poll(); delay(3_000) } }
        onPauseOrDispose { job.cancel() }
    }

    fun send(photo: File? = null) {
        val body = text.trim()
        if (body.isEmpty() && photo == null || sending) return
        sending = true
        scope.launch {
            try {
                withContext(Dispatchers.IO) {
                    if (photo != null) repo.api.multipart("/obrolan/$threadId/pesan", if (body.isEmpty()) emptyMap() else mapOf("body" to body), listOf("photo" to photo))
                    else repo.api.send("POST", "/obrolan/$threadId/pesan", JSONObject().put("body", body))
                }
                text = ""
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

    Scaffold(
        topBar = {
            TopAppBar(
                title = {
                    Column {
                        Text(info?.optString("title") ?: "Obrolan", fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        info?.s("subtitle")?.let { Text(it, fontSize = 12.sp, color = Color(0xFFDCCDB6), maxLines = 1, overflow = TextOverflow.Ellipsis) }
                    }
                },
                navigationIcon = { TextButton(onClick = onBack) { Text("‹", color = Color.White, fontSize = 24.sp) } },
                colors = TopAppBarDefaults.topAppBarColors(containerColor = Ink, titleContentColor = Color.White),
            )
        },
        containerColor = Color(0xFFF3EDE3),
    ) { padding ->
        Column(Modifier.padding(padding).imePadding()) {
            Banner(repo)
            LazyColumn(state = list, modifier = Modifier.weight(1f).fillMaxWidth(), contentPadding = PaddingValues(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                items(messages, key = { it.getLong("id") }) { m -> Bubble(repo, threadId, m, readUpto, { viewing = it }, { deleting = it }, onCard) }
            }
            if (info?.optBoolean("can_post", true) == false) {
                Text("Anda hanya memantau obrolan ini.", color = Muted, modifier = Modifier.fillMaxWidth().background(Color.White).padding(16.dp))
            } else Row(Modifier.fillMaxWidth().background(Color.White).padding(8.dp), verticalAlignment = Alignment.Bottom) {
                TextButton({ camera.launch(FileProvider.getUriForFile(context, context.packageName + ".files", cameraFile)) }, enabled = !sending) { Text("📷", fontSize = 20.sp) }
                TextButton({ gallery.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly)) }, enabled = !sending) { Text("🖼", fontSize = 20.sp) }
                OutlinedTextField(text, { text = it.take(4000) }, modifier = Modifier.weight(1f), maxLines = 5, placeholder = { Text("Tulis pesan") })
                TextButton(::send, enabled = !sending && text.isNotBlank()) { Text("Kirim", fontWeight = FontWeight.Bold) }
            }
        }
    }

    viewing?.let { id ->
        AlertDialog(
            onDismissRequest = { viewing = null },
            confirmButton = { TextButton({ viewing = null }) { Text("Tutup") } },
            text = { RemoteImage("chat-$id@1280", Modifier.fillMaxWidth().height(420.dp), crop = false) { Photos.thumbnail(repo.api.bytes("/obrolan/$threadId/foto/$id"), 1280) } },
        )
    }
    deleting?.let { m ->
        AlertDialog(
            onDismissRequest = { deleting = null },
            title = { Text("Hapus pesan untuk semua?") },
            confirmButton = { TextButton({ deleting = null; scope.launch { repo.call { it.send("DELETE", "/obrolan/$threadId/pesan/${m.getLong("id")}") }; messages.replaceAll { x -> if (x.getLong("id") == m.getLong("id")) JSONObject(x.toString()).put("deleted", true).put("body", JSONObject.NULL).put("photo", false) else x } } }) { Text("Hapus", color = Red) } },
            dismissButton = { TextButton({ deleting = null }) { Text("Batal") } },
        )
    }
}

@OptIn(ExperimentalFoundationApi::class)
@Composable
private fun Bubble(repo: Repo, threadId: Long, m: JSONObject, readUpto: Long, onPhoto: (Long) -> Unit, onDelete: (JSONObject) -> Unit, onCard: (String, Long) -> Unit) {
    val id = m.getLong("id")
    if (m.optBoolean("system")) {
        Text(m.optString("body"), fontSize = 12.sp, color = Muted, modifier = Modifier.fillMaxWidth().padding(vertical = 4.dp).wrapContentWidth().background(Color.White.copy(alpha = 0.7f), RoundedCornerShape(10.dp)).padding(horizontal = 10.dp, vertical = 4.dp))
        return
    }
    val mine = m.optBoolean("mine")
    Box(Modifier.fillMaxWidth(), contentAlignment = if (mine) Alignment.CenterEnd else Alignment.CenterStart) {
        Column(
            Modifier.widthIn(max = 300.dp).background(if (mine) Color(0xFFF6E7C1) else Color.White, RoundedCornerShape(14.dp))
                .combinedClickable(onClick = {}, onLongClick = { if (mine && !m.optBoolean("deleted")) onDelete(m) }).padding(10.dp),
            verticalArrangement = Arrangement.spacedBy(4.dp),
        ) {
            if (!mine) Text(m.optString("user"), fontSize = 12.sp, fontWeight = FontWeight.SemiBold, color = Gold)
            m.optJSONObject("reply")?.let { r ->
                Column(Modifier.fillMaxWidth().background(Color.Black.copy(alpha = 0.05f), RoundedCornerShape(8.dp)).padding(6.dp)) {
                    Text(r.optString("user"), fontSize = 11.sp, fontWeight = FontWeight.SemiBold, color = Gold)
                    Text(r.optString("text"), fontSize = 12.sp, color = Muted, maxLines = 2, overflow = TextOverflow.Ellipsis)
                }
            }
            if (m.optBoolean("deleted")) {
                Text("Pesan ini dihapus", fontSize = 14.sp, color = Muted)
            } else {
                if (m.optBoolean("photo")) RemoteImage("chat-$id@512", Modifier.size(220.dp).clickable { onPhoto(id) }) { Photos.thumbnail(repo.api.bytes("/obrolan/$threadId/foto/$id"), 512) }
                m.optJSONObject("card")?.let { c ->
                    val type = c.optString("type")
                    val opens = type == "kendala" || type == "permintaan"
                    Text(c.optString("text"), fontSize = 14.sp, fontWeight = FontWeight.SemiBold, color = if (opens) Gold else Ink,
                        modifier = Modifier.fillMaxWidth().background(Cream, RoundedCornerShape(8.dp)).clickable(enabled = opens) { onCard(type, c.getLong("id")) }.padding(8.dp))
                }
                m.s("file")?.let { Text("📄 $it (buka di web)", fontSize = 14.sp, color = Ink) }
                m.s("body")?.let { Text(it, fontSize = 15.sp, color = Ink) }
            }
            Text(shortTime(m.s("at")) + if (mine) (if (id <= readUpto) "  ✓✓" else "  ✓") else "", fontSize = 11.sp,
                color = if (mine && id <= readUpto) Color(0xFF2F80ED) else Muted, modifier = Modifier.align(Alignment.End))
        }
    }
}
