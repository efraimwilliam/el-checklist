package id.my.elhotelresortsanur.checklist

import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.FileProvider
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LifecycleEventEffect
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONObject
import java.io.File
import java.time.OffsetDateTime
import java.time.format.DateTimeFormatter
import java.util.Locale

/*
 * Kendala: selalu online (data resmi langsung dari server, tanpa antrean offline seperti checklist).
 * Aturan siapa boleh melihat / mengambil / mengisi status tetap di server (RoomIssuePolicy); layar hanya mengikuti `can`.
 */

internal fun JSONObject.s(key: String): String? = if (isNull(key)) null else optString(key).takeIf { it.isNotEmpty() }
internal fun JSONArray?.objects(): List<JSONObject> = if (this == null) emptyList() else (0 until length()).map { getJSONObject(it) }

private val TIME = DateTimeFormatter.ofPattern("d MMM yyyy, HH:mm", Locale("id", "ID"))
internal fun time(iso: String?) = iso?.let { runCatching { OffsetDateTime.parse(it).atZoneSameInstant(java.time.ZoneId.of("Asia/Makassar")).format(TIME) }.getOrNull() } ?: ""

private fun statusColor(status: String?) = when (status) { "selesai" -> Green; "dikerjakan" -> Amber; "tertunda" -> Red; else -> Gold }

private val KINDS = mapOf(
    "ambil" to "Diambil", "catatan" to "Ongoing", "tunda" to "Pending", "selesai" to "Done",
    "laporan" to "Laporan tambahan", "berulang" to "Muncul lagi", "lepas" to "Pengambilan dilepas",
)

/** Memanggil server di latar belakang; gagal = pesan di banner (401 = login lagi), hasil null. */
internal suspend fun <T> Repo.call(block: (Api) -> T): T? = withContext(Dispatchers.IO) {
    try {
        block(api).also { offline.value = false }
    } catch (e: ApiException) {
        when {
            e.code == 401 -> sessionEnded()
            e.code == 0 -> { offline.value = true; message.value = "Tidak ada koneksi. Menu ini butuh internet." }
            else -> message.value = e.message
        }
        null
    }
}

@Composable
internal fun Pill(text: String, color: Color) {
    Text(text, color = color, fontSize = 12.sp, fontWeight = FontWeight.SemiBold,
        modifier = Modifier.background(color.copy(alpha = 0.12f), RoundedCornerShape(50)).padding(horizontal = 10.dp, vertical = 3.dp))
}

// ---- Daftar ----

@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
fun IssuesScreen(repo: Repo, onOpen: (Long) -> Unit, onNew: () -> Unit) {
    val scope = rememberCoroutineScope()
    var done by rememberSaveable { mutableStateOf(false) }
    var issues by remember { mutableStateOf<List<JSONObject>?>(null) }
    var newForMe by remember { mutableIntStateOf(0) }
    var refreshing by remember { mutableStateOf(false) }

    fun load() = scope.launch {
        refreshing = true
        repo.call { it.get("/kendala" + if (done) "?status=selesai" else "") }?.let { res ->
            issues = res.optJSONArray("issues").objects()
            newForMe = res.optInt("new_for_me")
        }
        refreshing = false
    }
    LaunchedEffect(done) { issues = null; load() }
    LifecycleEventEffect(Lifecycle.Event.ON_RESUME) { load() }

    Scaffold(
        topBar = {
            TopAppBar(title = { Text("Kendala", fontWeight = FontWeight.SemiBold) },
                colors = TopAppBarDefaults.topAppBarColors(containerColor = Ink, titleContentColor = Color.White))
        },
        floatingActionButton = {
            ExtendedFloatingActionButton(onClick = onNew, containerColor = Ink, contentColor = Color.White) { Text("+ Catat kendala") }
        },
        containerColor = Cream,
    ) { padding ->
        Column(Modifier.padding(padding)) {
            Banner(repo)
            TabRow(selectedTabIndex = if (done) 1 else 0, containerColor = Color.White, contentColor = Ink) {
                Tab(!done, { done = false }, text = { Text("Terbuka" + if (newForMe > 0) " · $newForMe baru" else "") })
                Tab(done, { done = true }, text = { Text("Selesai") })
            }
            PullToRefreshBox(isRefreshing = refreshing, onRefresh = { load() }, modifier = Modifier.fillMaxSize()) {
                LazyColumn(contentPadding = PaddingValues(16.dp, 16.dp, 16.dp, 96.dp), verticalArrangement = Arrangement.spacedBy(12.dp), modifier = Modifier.fillMaxSize()) {
                    when {
                        issues == null -> item { Text(if (refreshing) "Memuat…" else "Belum bisa memuat. Tarik ke bawah untuk mencoba lagi.", color = Muted) }
                        issues!!.isEmpty() -> item { Text(if (done) "Belum ada kendala yang selesai." else "Tidak ada kendala terbuka untuk Anda.", color = Muted) }
                    }
                    items(issues.orEmpty(), key = { it.getLong("id") }) { issue -> IssueCard(issue) { onOpen(issue.getLong("id")) } }
                }
            }
        }
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun IssueCard(issue: JSONObject, onClick: () -> Unit) {
    val status = issue.s("status")
    val highlight = issue.optBoolean("for_me") && status == "baru"
    Card(Modifier.fillMaxWidth().clickable(onClick = onClick), shape = RoundedCornerShape(16.dp),
        colors = CardDefaults.cardColors(containerColor = if (highlight) GoldSoft else Color.White)) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(issue.s("place").orEmpty(), fontWeight = FontWeight.Bold, fontSize = 17.sp, color = Ink, modifier = Modifier.weight(1f))
                Pill(issue.s("status_label").orEmpty(), statusColor(status))
            }
            Text(listOfNotNull(issue.s("category"), issue.s("target")).joinToString(" · "), color = Muted, fontSize = 13.sp)
            FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                if (issue.s("priority") == "mendesak") Pill("Mendesak", Red)
                if (issue.optInt("recurrence") > 1) Pill("Berulang ke-${issue.optInt("recurrence")}", Amber)
                if (highlight) Pill("Untuk Anda", Gold)
            }
            Text(issue.s("excerpt").orEmpty(), color = Ink, fontSize = 14.sp)
            Text(
                (issue.s("claimer")?.let { "Diambil $it" } ?: "Belum diambil") + " · #" + issue.getLong("id") + " · " + time(issue.s("created_at")),
                color = Muted, fontSize = 12.sp,
            )
        }
    }
}

// ---- Detail ----

@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
fun IssueScreen(repo: Repo, id: Long, onBack: () -> Unit) {
    val scope = rememberCoroutineScope()
    var issue by remember { mutableStateOf<JSONObject?>(null) }
    var refreshing by remember { mutableStateOf(false) }
    var confirm by remember { mutableStateOf<String?>(null) } // "ambil" / "lepas"
    var viewing by remember { mutableStateOf<Long?>(null) }

    fun load() = scope.launch {
        refreshing = true
        repo.call { it.get("/kendala/$id") }?.let { issue = it.getJSONObject("issue") }
        refreshing = false
    }
    LaunchedEffect(id) { load() }

    fun act(path: String) = scope.launch {
        repo.call { it.send("POST", "/kendala/$id/$path") }?.let { repo.message.value = null }
        load()
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(issue?.s("place") ?: "Kendala", fontWeight = FontWeight.SemiBold) },
                navigationIcon = { TextButton(onClick = onBack) { Text("‹ Kembali", color = Color.White) } },
                colors = TopAppBarDefaults.topAppBarColors(containerColor = Ink, titleContentColor = Color.White),
            )
        },
        containerColor = Cream,
    ) { padding ->
        Column(Modifier.padding(padding)) {
            Banner(repo)
            PullToRefreshBox(isRefreshing = refreshing, onRefresh = { load() }, modifier = Modifier.fillMaxSize()) {
                val i = issue
                if (i == null) {
                    Text(if (refreshing) "Memuat…" else "Belum bisa memuat. Tarik ke bawah untuk mencoba lagi.", color = Muted, modifier = Modifier.padding(16.dp))
                    return@PullToRefreshBox
                }
                val can = i.optJSONObject("can") ?: JSONObject()
                LazyColumn(contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp), modifier = Modifier.fillMaxSize()) {
                    item {
                        Card(colors = CardDefaults.cardColors(containerColor = Color.White), shape = RoundedCornerShape(16.dp)) {
                            Column(Modifier.padding(16.dp).fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                                FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                                    Pill(i.s("status_label").orEmpty(), statusColor(i.s("status")))
                                    if (i.s("priority") == "mendesak") Pill("Mendesak", Red)
                                    if (i.optInt("recurrence") > 1) Pill("Berulang ke-${i.optInt("recurrence")}", Amber)
                                    Text("#$id", color = Muted, fontSize = 12.sp)
                                }
                                Text(i.s("category") ?: "Tanpa kategori", fontWeight = FontWeight.SemiBold, color = Ink)
                                Text(i.s("description").orEmpty(), color = Ink)
                                Text("Sumber: ${i.s("source").orEmpty()} · Dicatat ${i.s("reporter") ?: "—"}, ${time(i.s("created_at"))}", color = Muted, fontSize = 13.sp)
                                Text("Diteruskan ke: ${i.s("target").orEmpty()}", color = Muted, fontSize = 13.sp)
                            }
                        }
                    }
                    item {
                        Card(colors = CardDefaults.cardColors(containerColor = Color.White), shape = RoundedCornerShape(16.dp)) {
                            Column(Modifier.padding(16.dp).fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                                when {
                                    i.s("status") == "selesai" ->
                                        Text("Done oleh ${i.s("completer") ?: "—"}, ${time(i.s("completed_at"))}", color = Green, fontWeight = FontWeight.SemiBold)
                                    can.optBoolean("work") -> WorkForm(repo, id) { load() }
                                    can.optBoolean("claim") -> {
                                        Text("Belum ada yang mengambil.", color = Ink)
                                        Button({ confirm = "ambil" }, Modifier.fillMaxWidth().height(50.dp), colors = ButtonDefaults.buttonColors(containerColor = Ink)) { Text("Saya ambil") }
                                    }
                                    i.s("claimer") != null -> Text("Sedang dikerjakan ${i.s("claimer")} sejak ${time(i.s("claimed_at"))}.", color = Ink)
                                    else -> Text("Belum ada yang mengambil.", color = Muted)
                                }
                                if (can.optBoolean("release")) {
                                    TextButton(onClick = { confirm = "lepas" }) { Text("Lepas pengambilan", color = Red) }
                                }
                            }
                        }
                    }
                    item { Text("Riwayat pekerjaan", fontWeight = FontWeight.SemiBold, color = Ink) }
                    val updates = i.optJSONArray("updates").objects()
                    if (updates.isEmpty()) item { Text("Belum ada catatan pekerjaan.", color = Muted, fontSize = 14.sp) }
                    items(updates, key = { it.getLong("id") }) { u ->
                        Column(Modifier.fillMaxWidth().background(Color.White, RoundedCornerShape(12.dp)).padding(12.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                            Text(KINDS[u.s("kind")] ?: u.s("kind").orEmpty(), fontWeight = FontWeight.SemiBold, color = statusColor(mapOf("selesai" to "selesai", "tunda" to "tertunda", "catatan" to "dikerjakan")[u.s("kind")]))
                            u.s("body")?.let { Text(it, color = Ink, fontSize = 14.sp) }
                            Text(listOfNotNull(u.s("user"), u.s("department"), time(u.s("at"))).joinToString(" · "), color = Muted, fontSize = 12.sp)
                            val photos = u.optJSONArray("photos").objects()
                            if (photos.isNotEmpty()) FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                photos.forEach { p ->
                                    val pid = p.getLong("id")
                                    RemoteImage("issue-$pid@256", Modifier.size(72.dp).clickable { viewing = pid }) { Photos.thumbnail(repo.api.bytes("/kendala/$id/foto/$pid"), 256) }
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    confirm?.let { what ->
        AlertDialog(
            onDismissRequest = { confirm = null },
            title = { Text(if (what == "ambil") "Ambil kendala ini?" else "Lepas pengambilan?") },
            text = { Text(if (what == "ambil") "Kendala akan tercatat sedang Anda kerjakan. Rekan lain tidak bisa mengambilnya." else "Rekan lain bisa mengambil kendala ini.") },
            confirmButton = { TextButton(onClick = { confirm = null; act(what) }) { Text(if (what == "ambil") "Saya ambil" else "Lepas") } },
            dismissButton = { TextButton(onClick = { confirm = null }) { Text("Batal") } },
        )
    }
    viewing?.let { pid ->
        AlertDialog(
            onDismissRequest = { viewing = null },
            confirmButton = { TextButton(onClick = { viewing = null }) { Text("Tutup") } },
            text = { RemoteImage("issue-$pid@1280", Modifier.fillMaxWidth().height(400.dp), crop = false) { Photos.thumbnail(repo.api.bytes("/kendala/$id/foto/$pid"), 1280) } },
        )
    }
}

/** Status pekerjaan setelah diambil: Done / Ongoing / Pending (wajib beralasan), catatan, sampai 3 foto. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun WorkForm(repo: Repo, id: Long, onSaved: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var status by remember { mutableStateOf<String?>(null) }
    var body by remember { mutableStateOf("") }
    val photos = remember { mutableStateListOf<File>() }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val cameraFile = remember { File(context.cacheDir, "camera/issue-$id.jpg").apply { parentFile?.mkdirs() } }

    fun add(uris: List<Uri>) = scope.launch {
        uris.take(3 - photos.size).forEach { uri ->
            val out = File(context.cacheDir, "camera/issue-out-${System.nanoTime()}.jpg")
            if (withContext(Dispatchers.IO) { Photos.compress(context, uri, out) }) photos += out else error = "Foto tidak bisa dibaca."
        }
    }
    val camera = rememberLauncherForActivityResult(ActivityResultContracts.TakePicture()) { ok -> if (ok) add(listOf(Uri.fromFile(cameraFile))) }
    val gallery = rememberLauncherForActivityResult(ActivityResultContracts.PickMultipleVisualMedia(3)) { add(it) }

    fun save() {
        val chosen = status ?: return run { error = "Pilih statusnya." }
        if (chosen == "pending" && body.isBlank()) return run { error = "Tulis alasan Pending." }
        busy = true; error = null
        scope.launch {
            try {
                withContext(Dispatchers.IO) {
                    repo.api.multipart("/kendala/$id/status", mapOf("status" to chosen, "body" to body.trim()), photos.map { "photos[]" to it })
                }
                photos.forEach { it.delete() }; photos.clear(); body = ""; status = null
                onSaved()
            } catch (e: ApiException) {
                if (e.code == 401) repo.sessionEnded() else error = if (e.code == 0) "Tidak ada koneksi. Coba lagi saat ada internet." else e.message
            } finally {
                busy = false
            }
        }
    }

    Text("Status pekerjaan", fontWeight = FontWeight.SemiBold, color = Ink)
    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        listOf("done" to ("Done" to Green), "ongoing" to ("Ongoing" to Amber), "pending" to ("Pending" to Red)).forEach { (key, v) ->
            val (label, color) = v
            val on = status == key
            OutlinedButton(
                onClick = { status = if (on) null else key }, modifier = Modifier.weight(1f).height(48.dp),
                colors = ButtonDefaults.outlinedButtonColors(containerColor = if (on) color else Color.White, contentColor = if (on) Color.White else color),
            ) { Text(label) }
        }
    }
    OutlinedTextField(
        body, { body = it }, modifier = Modifier.fillMaxWidth(), minLines = 2,
        label = { Text(if (status == "pending") "Alasan Pending (wajib)" else "Catatan pekerjaan") },
        isError = status == "pending" && body.isBlank(),
    )
    if (photos.isNotEmpty()) FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        photos.forEach { f ->
            RemoteImage(f.path, Modifier.size(72.dp).clickable { photos.remove(f); f.delete() }) { Photos.thumbnail(f, 256) }
        }
    }
    if (photos.isNotEmpty()) Text("Ketuk foto untuk membatalkannya.", color = Muted, fontSize = 12.sp)
    if (photos.size < 3) Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        OutlinedButton({ camera.launch(FileProvider.getUriForFile(context, context.packageName + ".files", cameraFile)) }, Modifier.weight(1f)) { Text("Kamera") }
        OutlinedButton({ gallery.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly)) }, Modifier.weight(1f)) { Text("Galeri") }
    }
    error?.let { Text(it, color = Red, fontSize = 14.sp) }
    Button(::save, Modifier.fillMaxWidth().height(50.dp), enabled = !busy, colors = ButtonDefaults.buttonColors(containerColor = Ink)) {
        if (busy) CircularProgressIndicator(Modifier.size(22.dp), color = Color.White, strokeWidth = 2.dp) else Text("Simpan")
    }
}

// ---- Catat kendala ----

@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
fun NewIssueScreen(repo: Repo, onCreated: (Long?) -> Unit, onBack: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var form by remember { mutableStateOf<JSONObject?>(null) }
    var atRoom by rememberSaveable { mutableStateOf(true) }
    var room by rememberSaveable { mutableStateOf("") }
    var location by rememberSaveable { mutableStateOf("") }
    var category by rememberSaveable { mutableStateOf<Long?>(null) }
    var department by rememberSaveable { mutableStateOf<Long?>(null) }
    var source by rememberSaveable { mutableStateOf("tamu") }
    var urgent by rememberSaveable { mutableStateOf(false) }
    var description by rememberSaveable { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var duplicate by remember { mutableStateOf<JSONObject?>(null) }

    LaunchedEffect(Unit) { form = repo.call { it.get("/kendala/form") } }
    val rooms = remember(form) { form?.optJSONArray("rooms").objects() }
    val roomId = rooms.firstOrNull { it.optString("number").equals(room.trim(), ignoreCase = true) }?.getLong("id")
    val roomError = atRoom && room.isNotBlank() && form != null && roomId == null

    fun submit(extra: JSONObject.() -> Unit = {}) {
        error = when {
            atRoom && roomId == null -> if (room.isBlank()) "Tulis nomor kamarnya." else "Kamar ${room.trim()} tidak ada di data kamar. Periksa lagi nomornya."
            !atRoom && location.isBlank() -> "Tulis lokasinya."
            description.isBlank() -> "Tulis kendalanya dulu."
            else -> null
        }
        if (error != null) return
        busy = true
        val payload = JSONObject()
            .put("place_type", if (atRoom) "room" else "location")
            .put("room_id", if (atRoom) roomId else JSONObject.NULL)
            .put("location", if (atRoom) JSONObject.NULL else location.trim())
            .put("category_id", category ?: JSONObject.NULL)
            .put("target_type", "department")
            .put("assigned_department_id", department ?: JSONObject.NULL)
            .put("source", source)
            .put("priority", if (urgent) "mendesak" else "biasa")
            .put("description", description.trim())
            .apply(extra)
        scope.launch {
            try {
                val res = withContext(Dispatchers.IO) { repo.api.send("POST", "/kendala", payload) }
                android.widget.Toast.makeText(context, res.s("message") ?: "Tersimpan.", android.widget.Toast.LENGTH_SHORT).show()
                onCreated(if (res.optBoolean("can_view")) res.getLong("id") else null)
            } catch (e: ApiException) {
                when {
                    e.code == 409 && e.body?.optJSONObject("duplicate") != null -> duplicate = e.body.getJSONObject("duplicate").put("message", e.message)
                    e.code == 401 -> repo.sessionEnded()
                    else -> error = if (e.code == 0) "Tidak ada koneksi. Coba lagi saat ada internet." else e.message
                }
            } finally {
                busy = false
            }
        }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Catat kendala", fontWeight = FontWeight.SemiBold) },
                navigationIcon = { TextButton(onClick = onBack) { Text("‹ Batal", color = Color.White) } },
                colors = TopAppBarDefaults.topAppBarColors(containerColor = Ink, titleContentColor = Color.White),
            )
        },
        containerColor = Cream,
    ) { padding ->
        Column(Modifier.padding(padding).imePadding()) {
            Banner(repo)
            val f = form
            if (f == null) { Text("Memuat…", color = Muted, modifier = Modifier.padding(16.dp)); return@Column }
            Column(Modifier.verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Label("Lokasi")
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    FilterChip(atRoom, { atRoom = true }, { Text("Kamar") })
                    FilterChip(!atRoom, { atRoom = false }, { Text("Lokasi lain") })
                }
                if (atRoom) {
                    OutlinedTextField(room, { room = it }, label = { Text("Nomor kamar") }, singleLine = true, modifier = Modifier.fillMaxWidth(),
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), isError = roomError,
                        supportingText = if (roomError) { { Text("Kamar ${room.trim()} tidak ada di data kamar. Periksa lagi nomornya.") } } else null)
                } else {
                    OutlinedTextField(location, { location = it.take(120) }, label = { Text("Nama lokasi") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                    val suggestions = (0 until (f.optJSONArray("locations")?.length() ?: 0)).map { f.getJSONArray("locations").getString(it) }
                        .filter { location.isBlank() || it.contains(location.trim(), ignoreCase = true) }.take(10)
                    FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        suggestions.forEach { name -> SuggestionChip({ location = name }, { Text(name) }) }
                    }
                }

                Label("Kategori")
                FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    f.optJSONArray("categories").objects().forEach { c ->
                        val cid = c.getLong("id")
                        FilterChip(category == cid, { category = if (category == cid) null else cid }, { Text(c.optString("name")) })
                    }
                }

                Label("Diteruskan ke")
                FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    FilterChip(department == null, { department = null }, { Text("Sesuai kategori") })
                    f.optJSONArray("departments").objects().forEach { d ->
                        val did = d.getLong("id")
                        FilterChip(department == did, { department = did }, { Text(d.optString("name")) })
                    }
                }

                Label("Sumber")
                FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    f.optJSONArray("sources").objects().forEach { src ->
                        FilterChip(source == src.optString("key"), { source = src.optString("key") }, { Text(src.optString("label")) })
                    }
                }

                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text("Mendesak", color = Ink, modifier = Modifier.weight(1f))
                    Switch(urgent, { urgent = it })
                }

                OutlinedTextField(description, { description = it.take(2000) }, label = { Text("Kendalanya") }, minLines = 3, modifier = Modifier.fillMaxWidth())
                error?.let { Text(it, color = Red, fontSize = 14.sp) }
                Button({ submit() }, Modifier.fillMaxWidth().height(50.dp), enabled = !busy, colors = ButtonDefaults.buttonColors(containerColor = Ink)) {
                    if (busy) CircularProgressIndicator(Modifier.size(22.dp), color = Color.White, strokeWidth = 2.dp) else Text("Simpan kendala")
                }
                Spacer(Modifier.height(24.dp))
            }
        }
    }

    // Satu lokasi + satu kategori + belum selesai = satu kendala: tawarkan menambah laporan ke kendala itu.
    duplicate?.let { d ->
        AlertDialog(
            onDismissRequest = { duplicate = null },
            title = { Text("Sudah ada kendala serupa") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    Text(d.s("message").orEmpty())
                    Text("#${d.optLong("id")} · ${d.s("status").orEmpty()}" + (d.s("claimer")?.let { " · diambil $it" } ?: ""), color = Muted, fontSize = 13.sp)
                    d.s("description")?.let { Text(it, fontSize = 14.sp) }
                }
            },
            confirmButton = { TextButton(onClick = { duplicate = null; submit { put("merge_into", d.optLong("id")) } }) { Text("Tambahkan laporan") } },
            dismissButton = { TextButton(onClick = { duplicate = null; submit { put("force_new", true) } }) { Text("Buat kendala baru") } },
        )
    }
}

@Composable
internal fun Label(text: String) = Text(text, fontWeight = FontWeight.SemiBold, color = Ink, fontSize = 14.sp)
