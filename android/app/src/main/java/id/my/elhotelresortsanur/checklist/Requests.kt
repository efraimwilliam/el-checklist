package id.my.elhotelresortsanur.checklist

import androidx.compose.foundation.background
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
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LifecycleEventEffect
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONObject
import java.time.Duration
import java.time.OffsetDateTime

/*
 * Permintaan tamu (air mineral, handuk, laundry, ...): selalu online seperti Kendala.
 * Aksi per baris (Saya ambil / Selesai / Lepas / Hapus) mengikuti `can` dari server (GuestRequestPolicy).
 */

private fun waitingMinutes(iso: String?): Long =
    iso?.let { runCatching { Duration.between(OffsetDateTime.parse(it), OffsetDateTime.now()).toMinutes() }.getOrNull() } ?: 0

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun RequestsScreen(repo: Repo, onNew: () -> Unit) {
    val scope = rememberCoroutineScope()
    var done by rememberSaveable { mutableStateOf(false) }
    var rows by remember { mutableStateOf<List<JSONObject>?>(null) }
    var newForMe by remember { mutableIntStateOf(0) }
    var refreshing by remember { mutableStateOf(false) }
    var confirmDelete by remember { mutableStateOf<JSONObject?>(null) }
    var tick by remember { mutableIntStateOf(0) } // menit menunggu & warna baris diperbarui tiap menit

    fun load() = scope.launch {
        refreshing = true
        repo.call { it.get("/permintaan" + if (done) "?status=selesai" else "") }?.let { res ->
            rows = res.optJSONArray("requests").objects()
            newForMe = res.optInt("new_for_me")
        }
        refreshing = false
    }
    fun act(row: JSONObject, method: String, path: String) = scope.launch {
        repo.call { it.send(method, "/permintaan/${row.getLong("id")}$path") }
        load()
    }
    LaunchedEffect(done) { rows = null; load() }
    LifecycleEventEffect(Lifecycle.Event.ON_RESUME) { load() }
    LaunchedEffect(Unit) { while (true) { delay(60_000); tick++ } }

    Scaffold(
        topBar = {
            TopAppBar(title = { Text("Permintaan tamu", fontWeight = FontWeight.SemiBold) },
                colors = TopAppBarDefaults.topAppBarColors(containerColor = Ink, titleContentColor = Color.White))
        },
        floatingActionButton = {
            ExtendedFloatingActionButton(onClick = onNew, containerColor = Ink, contentColor = Color.White) { Text("+ Catat permintaan") }
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
                LazyColumn(contentPadding = PaddingValues(16.dp, 16.dp, 16.dp, 96.dp), verticalArrangement = Arrangement.spacedBy(10.dp), modifier = Modifier.fillMaxSize()) {
                    when {
                        rows == null -> item { Text(if (refreshing) "Memuat…" else "Belum bisa memuat. Tarik ke bawah untuk mencoba lagi.", color = Muted) }
                        rows!!.isEmpty() -> item { Text(if (done) "Belum ada permintaan yang selesai." else "Tidak ada permintaan yang menunggu.", color = Muted) }
                    }
                    items(rows.orEmpty(), key = { it.getLong("id") }) { row ->
                        RequestRow(row, tick,
                            onClaim = { act(row, "POST", "/ambil") }, onDone = { act(row, "POST", "/selesai") },
                            onRelease = { act(row, "POST", "/lepas") }, onDelete = { confirmDelete = row })
                    }
                }
            }
        }
    }

    confirmDelete?.let { row ->
        AlertDialog(
            onDismissRequest = { confirmDelete = null },
            title = { Text("Hapus permintaan?") },
            text = { Text("Kamar ${row.s("room").orEmpty()} · ${row.s("label").orEmpty()}") },
            confirmButton = { TextButton(onClick = { confirmDelete = null; act(row, "DELETE", "") }) { Text("Hapus", color = Red) } },
            dismissButton = { TextButton(onClick = { confirmDelete = null }) { Text("Batal") } },
        )
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun RequestRow(row: JSONObject, tick: Int, onClaim: () -> Unit, onDone: () -> Unit, onRelease: () -> Unit, onDelete: () -> Unit) {
    val status = row.s("status")
    val waited = remember(row, tick) { waitingMinutes(row.s("created_at")) }
    // Sama dengan web: Baru yang menunggu >= 15 menit kuning, >= 30 menit merah.
    val background = when {
        status != "baru" -> Color.White
        waited >= 30 -> Color(0xFFFBE9E5)
        waited >= 15 -> Color(0xFFFDF3D8)
        else -> Color.White
    }
    val can = row.optJSONObject("can") ?: JSONObject()
    Column(Modifier.fillMaxWidth().background(background, RoundedCornerShape(16.dp)).padding(14.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(row.s("room").orEmpty(), fontWeight = FontWeight.Bold, fontSize = 20.sp, color = Ink, modifier = Modifier.width(72.dp))
            Text(row.s("label").orEmpty(), fontWeight = FontWeight.SemiBold, color = Ink, modifier = Modifier.weight(1f))
            Pill(row.s("status_label").orEmpty(), when (status) { "selesai" -> Green; "diambil" -> Amber; else -> Gold })
        }
        FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            if (row.s("priority") == "mendesak") Pill("Mendesak", Red)
            if (status == "baru") Text("Menunggu $waited menit", color = if (waited >= 30) Red else if (waited >= 15) Amber else Muted, fontSize = 13.sp)
        }
        row.s("note")?.let { Text(it, color = Ink, fontSize = 14.sp) }
        Text(
            listOfNotNull(
                row.s("department"),
                "dicatat ${row.s("requester") ?: "—"}",
                row.s("claimer")?.takeIf { status == "diambil" }?.let { "diambil $it" },
                row.s("completer")?.let { "selesai $it, ${time(row.s("completed_at"))}" },
            ).joinToString(" · "),
            color = Muted, fontSize = 12.sp,
        )
        if (can.optBoolean("claim") || can.optBoolean("complete") || can.optBoolean("release") || can.optBoolean("delete")) {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                if (can.optBoolean("claim")) Button(onClaim, Modifier.weight(1f), colors = ButtonDefaults.buttonColors(containerColor = Ink)) { Text("Saya ambil") }
                if (can.optBoolean("complete")) Button(onDone, Modifier.weight(1f), colors = ButtonDefaults.buttonColors(containerColor = Green)) { Text("Selesai") }
                if (can.optBoolean("release")) TextButton(onRelease) { Text("Lepas", color = Muted) }
                if (can.optBoolean("delete")) TextButton(onDelete) { Text("Hapus", color = Red) }
            }
        }
    }
}

// ---- Catat permintaan ----

@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
fun NewRequestScreen(repo: Repo, onDone: () -> Unit, onBack: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var form by remember { mutableStateOf<JSONObject?>(null) }
    var room by rememberSaveable { mutableStateOf("") }
    val qty = remember { mutableStateMapOf<String, Int>() } // jenis tercentang -> jumlah
    var note by rememberSaveable { mutableStateOf("") }
    var urgent by rememberSaveable { mutableStateOf(false) }
    var department by rememberSaveable { mutableStateOf<Long?>(null) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(Unit) { form = repo.call { it.get("/permintaan/form") } }

    fun submit() {
        error = when {
            room.isBlank() -> "Tulis nomor kamarnya."
            qty.isEmpty() -> "Pilih minimal satu permintaan."
            else -> null
        }
        if (error != null) return
        busy = true
        val payload = JSONObject()
            .put("room", room.trim())
            .put("kinds", JSONArray(qty.keys.toList()))
            .put("qty", JSONObject(qty.mapValues { it.value }))
            .put("note", note.trim().ifEmpty { null } ?: JSONObject.NULL)
            .put("priority", if (urgent) "mendesak" else "biasa")
            .put("assigned_department_id", department ?: JSONObject.NULL)
        scope.launch {
            try {
                val res = withContext(Dispatchers.IO) { repo.api.send("POST", "/permintaan", payload) }
                android.widget.Toast.makeText(context, res.s("message") ?: "Tersimpan.", android.widget.Toast.LENGTH_SHORT).show()
                onDone()
            } catch (e: ApiException) {
                if (e.code == 401) repo.sessionEnded() else error = if (e.code == 0) "Tidak ada koneksi. Coba lagi saat ada internet." else e.message
            } finally {
                busy = false
            }
        }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Catat permintaan", fontWeight = FontWeight.SemiBold) },
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
                OutlinedTextField(room, { room = it.take(30) }, label = { Text("Nomor kamar") }, singleLine = true, modifier = Modifier.fillMaxWidth(),
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number))

                Label("Permintaan")
                Column(Modifier.fillMaxWidth().background(Color.White, RoundedCornerShape(12.dp))) {
                    f.optJSONArray("kinds").objects().forEach { kind ->
                        val key = kind.getString("key")
                        val checked = key in qty
                        Row(Modifier.fillMaxWidth().padding(horizontal = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                            Checkbox(checked, { on -> if (on) qty[key] = 1 else qty.remove(key) })
                            Text(kind.optString("label"), color = Ink, modifier = Modifier.weight(1f))
                            if (checked && kind.optBoolean("has_quantity")) {
                                TextButton(onClick = { qty[key] = ((qty[key] ?: 1) - 1).coerceAtLeast(1) }) { Text("−", fontSize = 20.sp) }
                                Text("${qty[key]}", fontWeight = FontWeight.Bold, color = Ink)
                                TextButton(onClick = { qty[key] = ((qty[key] ?: 1) + 1).coerceAtMost(20) }) { Text("+", fontSize = 20.sp) }
                            }
                        }
                    }
                }

                OutlinedTextField(note, { note = it.take(500) }, label = { Text("Catatan (opsional)") }, modifier = Modifier.fillMaxWidth(), minLines = 2)

                Label("Diteruskan ke")
                FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    FilterChip(department == null, { department = null }, { Text("Sesuai jenis") })
                    f.optJSONArray("departments").objects().forEach { d ->
                        val did = d.getLong("id")
                        FilterChip(department == did, { department = did }, { Text(d.optString("name")) })
                    }
                }

                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text("Mendesak", color = Ink, modifier = Modifier.weight(1f))
                    Switch(urgent, { urgent = it })
                }
                error?.let { Text(it, color = Red, fontSize = 14.sp) }
                Button(::submit, Modifier.fillMaxWidth().height(50.dp), enabled = !busy, colors = ButtonDefaults.buttonColors(containerColor = Ink)) {
                    if (busy) CircularProgressIndicator(Modifier.size(22.dp), color = Color.White, strokeWidth = 2.dp)
                    else Text(if (qty.size > 1) "Simpan ${qty.size} permintaan" else "Simpan permintaan")
                }
                Spacer(Modifier.height(24.dp))
            }
        }
    }
}
