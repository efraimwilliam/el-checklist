package id.my.elhotelresortsanur.checklist

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.*
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.time.LocalDate

/*
 * Papan Isi checklist untuk HOD / HR / Administrator (sama dengan halaman web /isi): tanggal + departemen, lalu tiap staff aktif
 * dengan status checklistnya. Ketuk = buka / mulai checklist (isi memakai FillScreen yang sama, tetap bisa offline sesudah dimuat).
 * Papan sendiri butuh internet. Hak mulai / buat sekaligus / review tetap diperiksa server.
 */

private val STATES = mapOf(
    "none" to ("Belum dibuat" to Muted), "noschedule" to ("Jadwal belum diisi" to Muted), "empty" to ("Belum diisi" to Amber),
    "partial" to ("Belum lengkap" to Amber), "complete" to ("Lengkap" to Green), "hod_reviewed" to ("Direview HOD" to Green), "reviewed" to ("Direview GM/HR" to Green),
)

private val FILTERS = listOf("semua" to "Semua", "perlu" to "Perlu diisi", "lengkap" to "Lengkap", "review" to "Direview")

private fun matches(filter: String, state: String) = when (filter) {
    "perlu" -> state in setOf("none", "empty", "partial")
    "lengkap" -> state == "complete"
    "review" -> state in setOf("hod_reviewed", "reviewed")
    else -> true
}

@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
fun BoardScreen(repo: Repo, onOpen: (Long) -> Unit, onHistory: (() -> Unit)? = null) {
    val context = androidx.compose.ui.platform.LocalContext.current
    val scope = rememberCoroutineScope()
    var date by rememberSaveable { mutableStateOf<String?>(null) }
    var department by rememberSaveable { mutableStateOf<Long?>(null) }
    var filter by rememberSaveable { mutableStateOf("semua") }
    var board by remember { mutableStateOf<JSONObject?>(null) }
    var refreshing by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var confirmAll by remember { mutableStateOf(false) }
    var summary by remember { mutableStateOf<JSONObject?>(null) }

    fun load() = scope.launch {
        refreshing = true
        val query = listOfNotNull(date?.let { "date=$it" }, department?.let { "department=$it" }).joinToString("&")
        repo.call { it.get("/papan" + if (query.isEmpty()) "" else "?$query") }?.let { res ->
            board = res; date = res.optString("date"); department = res.optLong("department_id").takeIf { it > 0 }
        }
        refreshing = false
    }
    LaunchedEffect(date, department) { load() }

    fun start(row: JSONObject) {
        val b = board ?: return
        busy = true
        scope.launch {
            try {
                val res = withContext(Dispatchers.IO) {
                    repo.api.send("POST", "/papan/mulai", JSONObject().put("employee", row.getLong("employee_id")).put("position", row.getLong("position_id")).put("date", b.getString("date")))
                }
                onOpen(res.getLong("id"))
            } catch (e: ApiException) {
                when {
                    e.code == 409 && e.body?.has("id") == true -> onOpen(e.body.getLong("id"))
                    e.code == 401 -> repo.sessionEnded()
                    else -> repo.message.value = if (e.code == 0) "Tidak ada koneksi. Papan butuh internet." else e.message
                }
            } finally {
                busy = false
            }
        }
    }

    fun assignAll() = scope.launch {
        val b = board ?: return@launch
        busy = true
        repo.call { it.send("POST", "/papan/semua", JSONObject().put("date", b.getString("date")).put("department", b.optLong("department_id").toString())) }
            ?.let { summary = it.getJSONObject("summary") }
        busy = false
        load()
    }

    Scaffold(
        topBar = {
            TopAppBar(title = { Text("Isi checklist", fontWeight = FontWeight.SemiBold) },
                actions = { onHistory?.let { TextButton(onClick = it) { Text("Riwayat", color = Color.White) } } },
                colors = TopAppBarDefaults.topAppBarColors(containerColor = Ink, titleContentColor = Color.White))
        },
        containerColor = Cream,
    ) { padding ->
        Column(Modifier.padding(padding)) {
            Banner(repo)
            val b = board
            PullToRefreshBox(isRefreshing = refreshing, onRefresh = { load() }, modifier = Modifier.fillMaxSize()) {
                if (b == null) {
                    Text(if (refreshing) "Memuat…" else "Belum bisa memuat. Tarik ke bawah untuk mencoba lagi.", color = Muted, modifier = Modifier.padding(16.dp))
                    return@PullToRefreshBox
                }
                val staff = b.optJSONArray("staff").objects()
                val day = LocalDate.parse(b.getString("date"))
                val today = LocalDate.parse(b.getString("today"))
                LazyColumn(contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp), modifier = Modifier.fillMaxSize()) {
                    item {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            TextButton({ date = day.minusDays(1).toString() }) { Text("‹", fontSize = 22.sp) }
                            Text(formatDate(day.toString()), fontWeight = FontWeight.SemiBold, color = Ink, modifier = Modifier.weight(1f))
                            if (day < today) TextButton({ date = day.plusDays(1).toString() }) { Text("›", fontSize = 22.sp) }
                            if (day != today) TextButton({ date = today.toString() }) { Text("Hari ini") }
                        }
                    }
                    val departments = b.optJSONArray("departments").objects()
                    if (departments.size > 1) item {
                        FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                            departments.forEach { d ->
                                FilterChip(b.optLong("department_id") == d.getLong("id"), { department = d.getLong("id") }, { Text(d.optString("name")) })
                            }
                        }
                    }
                    item {
                        FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                            FILTERS.forEach { (key, label) ->
                                val count = staff.count { matches(key, it.optString("state")) }
                                FilterChip(filter == key, { filter = key }, { Text("$label · $count") })
                            }
                        }
                    }
                    b.s("schedule_problem")?.let { problem ->
                        item { Text("Jadwal Absensi tidak terbaca: $problem", color = Amber, fontSize = 13.sp, modifier = Modifier.fillMaxWidth().background(GoldSoft, RoundedCornerShape(12.dp)).padding(12.dp)) }
                    }
                    if (b.optBoolean("can_assign_all")) item {
                        OutlinedButton({ confirmAll = true }, Modifier.fillMaxWidth(), enabled = !busy) { Text("Buat checklist untuk yang masuk kerja") }
                    }
                    val shown = staff.filter { matches(filter, it.optString("state")) }
                    if (shown.isEmpty()) item { Text("Tidak ada staff di sini.", color = Muted) }
                    items(shown, key = { it.getLong("employee_id") }) { row -> StaffRow(row, busy, onOpen) { start(row) } }
                }
            }
        }
    }

    if (confirmAll) AlertDialog(
        onDismissRequest = { confirmAll = false },
        title = { Text("Buat checklist sekaligus?") },
        text = { Text("Checklist kosong dibuatkan untuk setiap staff yang masuk kerja menurut jadwal Absensi. Yang sudah ada tidak diubah.") },
        confirmButton = { TextButton({ confirmAll = false; assignAll() }) { Text("Buat") } },
        dismissButton = { TextButton({ confirmAll = false }) { Text("Batal") } },
    )
    summary?.let { s ->
        val leave = s.optJSONObject("leave")?.let { l -> l.keys().asSequence().sumOf { l.optInt(it) } } ?: 0
        val noPosition = s.optJSONArray("no_position").objects().map { it.optString("name") }
        AlertDialog(
            onDismissRequest = { summary = null },
            title = { Text("Hasil") },
            text = {
                Text(buildString {
                    append("Dibuat: ${s.optInt("created")} · sudah ada: ${s.optInt("existing")}\n")
                    append("Libur: ${s.optInt("off")} · izin/cuti: $leave · jadwal belum diisi: ${s.optInt("unscheduled")}")
                    if (noPosition.isNotEmpty()) append("\n\nJabatan checklist belum diatur: ${noPosition.joinToString(", ")}")
                })
            },
            confirmButton = { TextButton({ summary = null }) { Text("Tutup") } },
        )
    }
}

@Composable
private fun StaffRow(row: JSONObject, busy: Boolean, onOpen: (Long) -> Unit, onStart: () -> Unit) {
    val state = row.optString("state")
    val (label, color) = STATES[state] ?: (state to Muted)
    val checklists = row.optJSONArray("checklists").objects()
    val first = checklists.firstOrNull()
    val faded = state == "noschedule"
    Column(
        Modifier.fillMaxWidth().background(if (faded) Color(0xFFF1ECE4) else Color.White, RoundedCornerShape(14.dp))
            .clickable(enabled = first != null) { first?.let { onOpen(it.getLong("id")) } }.padding(14.dp),
        verticalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(row.optString("name"), fontWeight = FontWeight.SemiBold, color = if (faded) Muted else Ink)
                Text(row.s("position") ?: row.s("title") ?: "", color = Muted, fontSize = 13.sp)
            }
            Pill(label, color)
        }
        checklists.forEach { c ->
            val p = c.optJSONObject("progress") ?: JSONObject()
            val total = p.optInt("total")
            if (checklists.size > 1) Text(c.optString("position"), fontSize = 12.sp, color = Muted)
            LinearProgressIndicator(
                progress = { if (total == 0) 0f else p.optInt("filled").toFloat() / total },
                modifier = Modifier.fillMaxWidth().height(6.dp), color = Gold, trackColor = GoldSoft,
            )
            Text("${p.optInt("filled")}/$total terisi" + (if (p.isNull("score")) "" else " · Skor ${p.optInt("score")}%"), fontSize = 12.sp, color = Ink)
        }
        when {
            first != null -> {}
            row.optBoolean("can_start") -> Button(onStart, Modifier.fillMaxWidth(), enabled = !busy, colors = ButtonDefaults.buttonColors(containerColor = Ink)) {
                Text("Mulai · ${row.optString("position")}")
            }
            state == "noschedule" -> Text("Jadwal di Absensi belum diisi untuk tanggal ini.", fontSize = 12.sp, color = Muted)
            row.isNull("position_id") -> Text("Jabatan checklist belum diatur (atur di web: Tugas & jabatan).", fontSize = 12.sp, color = Amber)
            else -> Text("Anda tidak bisa memulai checklist staff ini.", fontSize = 12.sp, color = Muted)
        }
    }
}
