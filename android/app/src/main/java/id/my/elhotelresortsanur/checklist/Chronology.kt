package id.my.elhotelresortsanur.checklist

import android.app.DatePickerDialog
import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONObject
import java.time.LocalDate

/*
 * Chronology Report (laporan kejadian tamu, bahasa Inggris tetap): daftar, lihat + salin teks (format WhatsApp, sama dengan web),
 * buat / ubah draft, finalisasi. Tempel dari WhatsApp, cetak / PDF, dan buka kembali tetap di web. Butuh internet.
 */

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ChronologyListScreen(repo: Repo, onOpen: (Long) -> Unit, onNew: () -> Unit, onBack: () -> Unit) {
    val scope = rememberCoroutineScope()
    var reports by remember { mutableStateOf<List<JSONObject>?>(null) }
    var refreshing by remember { mutableStateOf(false) }
    fun load() = scope.launch { refreshing = true; repo.call { it.get("/chronology") }?.let { reports = it.optJSONArray("reports").objects() }; refreshing = false }
    LaunchedEffect(Unit) { load() }

    Scaffold(
        topBar = {
            TopAppBar(title = { Text("Chronology Report", fontWeight = FontWeight.SemiBold) },
                navigationIcon = { BackButton(onBack) },
                colors = TopAppBarDefaults.topAppBarColors(containerColor = Ink, titleContentColor = Color.White))
        },
        floatingActionButton = { AddButton("Laporan baru", onNew) },
        containerColor = Cream,
    ) { padding ->
        Column(Modifier.padding(padding)) {
            Banner(repo)
            PullToRefreshBox(isRefreshing = refreshing, onRefresh = { load() }, modifier = Modifier.fillMaxSize()) {
                LazyColumn(contentPadding = PaddingValues(16.dp, 16.dp, 16.dp, 96.dp), verticalArrangement = Arrangement.spacedBy(10.dp), modifier = Modifier.fillMaxSize()) {
                    when {
                        reports == null -> item { LoadingOr(refreshing) }
                        reports!!.isEmpty() -> item { EmptyState(androidx.compose.ui.res.painterResource(R.drawable.ic_description), "Belum ada laporan", "Ketuk \"Laporan baru\" untuk membuat Chronology Report.") }
                    }
                    items(reports.orEmpty(), key = { it.getLong("id") }) { r ->
                        Column(Modifier.fillMaxWidth().background(Color.White, RoundedCornerShape(14.dp)).clickable { onOpen(r.getLong("id")) }.padding(14.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Text(r.s("guest") ?: "—", fontWeight = FontWeight.SemiBold, color = Ink, modifier = Modifier.weight(1f))
                                Pill(if (r.optString("status") == "final") "Final" else "Draft", if (r.optString("status") == "final") Green else Amber)
                            }
                            Text(listOfNotNull(r.s("room")?.let { "Kamar $it" }, "${r.optInt("entries")} poin", r.s("author"), time(r.s("created_at"))).joinToString(" · "), color = Muted, fontSize = 13.sp)
                        }
                    }
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ChronologyScreen(repo: Repo, id: Long, onEdit: () -> Unit, onBack: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var report by remember { mutableStateOf<JSONObject?>(null) }
    var confirm by remember { mutableStateOf(false) }
    fun load() = scope.launch { repo.call { it.get("/chronology/$id") }?.let { report = it.getJSONObject("report") } }
    LaunchedEffect(id) { load() }

    Scaffold(
        topBar = {
            TopAppBar(title = { Text("Chronology Report", fontWeight = FontWeight.SemiBold) },
                navigationIcon = { BackButton(onBack) },
                colors = TopAppBarDefaults.topAppBarColors(containerColor = Ink, titleContentColor = Color.White))
        },
        containerColor = Cream,
    ) { padding ->
        Column(Modifier.padding(padding)) {
            Banner(repo)
            val r = report ?: return@Column Loading()
            Column(Modifier.verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Pill(if (r.optString("status") == "final") "Final · terkunci" else "Draft", if (r.optString("status") == "final") Green else Amber)
                Text(r.optString("text"), color = Ink, fontSize = 15.sp, modifier = Modifier.fillMaxWidth().background(Color.White, RoundedCornerShape(14.dp)).padding(16.dp))
                Button({
                    (context.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager).setPrimaryClip(ClipData.newPlainText("Chronology Report", r.optString("text")))
                    android.widget.Toast.makeText(context, "Teks disalin. Tempel di WhatsApp.", android.widget.Toast.LENGTH_SHORT).show()
                }, Modifier.fillMaxWidth(), colors = ButtonDefaults.buttonColors(containerColor = Ink)) { Text("Salin teks") }
                if (r.optJSONObject("can")?.optBoolean("update") == true) {
                    OutlinedButton(onEdit, Modifier.fillMaxWidth()) { Text("Ubah draft") }
                    Button({ confirm = true }, Modifier.fillMaxWidth(), colors = ButtonDefaults.buttonColors(containerColor = Green)) { Text("Finalisasi") }
                }
            }
        }
    }
    if (confirm) AlertDialog(
        onDismissRequest = { confirm = false },
        title = { Text("Finalisasi laporan?") },
        text = { Text("Sesudah final, laporan terkunci. Membuka kembali hanya oleh HR / Administrator.") },
        confirmButton = { TextButton({ confirm = false; scope.launch { repo.call { it.send("POST", "/chronology/$id/final") }; load() } }) { Text("Finalisasi") } },
        dismissButton = { TextButton({ confirm = false }) { Text("Batal") } },
    )
}

/** Form buat / ubah draft (id null = baru). Tamu dan poin bisa ditambah / dihapus; yang kosong dibuang server. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ChronologyFormScreen(repo: Repo, id: Long?, onSaved: (Long) -> Unit, onBack: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val guests = remember { mutableStateListOf(arrayOf("", "")) }
    val entries = remember { mutableStateListOf("") }
    var nationality by remember { mutableStateOf("") }
    var source by remember { mutableStateOf("") }
    var checkIn by remember { mutableStateOf(LocalDate.now().toString()) }
    var checkOut by remember { mutableStateOf("") }
    var loaded by remember { mutableStateOf(id == null) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(id) {
        if (id == null) return@LaunchedEffect
        repo.call { it.get("/chronology/$id") }?.getJSONObject("report")?.let { r ->
            nationality = r.s("nationality").orEmpty(); source = r.s("booking_source").orEmpty()
            checkIn = r.s("check_in").orEmpty(); checkOut = r.s("check_out").orEmpty()
            guests.clear(); r.optJSONArray("guests").objects().forEach { guests += arrayOf(it.optString("name"), it.s("room_number").orEmpty()) }
            entries.clear(); r.optJSONArray("entries").objects().forEach { entries += it.optString("body") }
            if (guests.isEmpty()) guests += arrayOf("", ""); if (entries.isEmpty()) entries += ""
            loaded = true
        }
    }

    fun pick(current: String, set: (String) -> Unit) {
        val d = runCatching { LocalDate.parse(current) }.getOrDefault(LocalDate.now())
        DatePickerDialog(context, { _, y, m, day -> set(LocalDate.of(y, m + 1, day).toString()) }, d.year, d.monthValue - 1, d.dayOfMonth).show()
    }

    fun save(final: Boolean) {
        busy = true; error = null
        val body = JSONObject()
            .put("nationality", nationality.trim()).put("booking_source", source.trim())
            .put("check_in", checkIn).put("check_out", checkOut.ifBlank { null } ?: JSONObject.NULL)
            .put("guests", JSONArray(guests.map { JSONObject().put("name", it[0].trim()).put("room_number", it[1].trim()) }))
            .put("entries", JSONArray(entries.map { JSONObject().put("body", it.trim()) }))
            .put("intent", if (final) "final" else "draft")
        scope.launch {
            try {
                val res = withContext(Dispatchers.IO) { if (id == null) repo.api.send("POST", "/chronology", body) else repo.api.send("PUT", "/chronology/$id", body) }
                android.widget.Toast.makeText(context, res.s("message") ?: "Tersimpan.", android.widget.Toast.LENGTH_SHORT).show()
                onSaved(res.getLong("id"))
            } catch (e: ApiException) {
                if (e.code == 401) repo.sessionEnded() else error = if (e.code == 0) "Tidak ada koneksi. Coba lagi saat ada internet." else e.message
            } finally {
                busy = false
            }
        }
    }

    Scaffold(
        topBar = {
            TopAppBar(title = { Text(if (id == null) "Laporan baru" else "Ubah draft", fontWeight = FontWeight.SemiBold) },
                navigationIcon = { BackButton(onBack, "Batal") },
                colors = TopAppBarDefaults.topAppBarColors(containerColor = Ink, titleContentColor = Color.White))
        },
        containerColor = Cream,
    ) { padding ->
        Column(Modifier.padding(padding).imePadding()) {
            Banner(repo)
            if (!loaded) return@Column Loading()
            Column(Modifier.verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                Text("Isi dalam bahasa Inggris (laporan untuk manajemen).", color = Muted, fontSize = 13.sp)
                Label("Tamu")
                guests.forEachIndexed { i, g ->
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                        OutlinedTextField(g[0], { guests[i] = arrayOf(it.take(120), g[1]) }, label = { Text("Guest Name") }, singleLine = true, modifier = Modifier.weight(2f))
                        OutlinedTextField(g[1], { guests[i] = arrayOf(g[0], it.take(30)) }, label = { Text("Room") }, singleLine = true, modifier = Modifier.weight(1f))
                        if (guests.size > 1) TextButton({ guests.removeAt(i) }) { Text("×", color = Red, fontSize = 20.sp) }
                    }
                }
                if (guests.size < 10) TextButton({ guests += arrayOf("", "") }) { Text("+ Tamu") }
                OutlinedTextField(nationality, { nationality = it.take(80) }, label = { Text("Nationality") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedButton({ pick(checkIn) { checkIn = it } }, Modifier.weight(1f)) { Text("Check-in: ${checkIn.ifBlank { "—" }}") }
                    OutlinedButton({ pick(checkOut.ifBlank { checkIn }) { checkOut = it } }, Modifier.weight(1f)) { Text("Check-out: ${checkOut.ifBlank { "—" }}") }
                }
                OutlinedTextField(source, { source = it.take(120) }, label = { Text("Source of Booking") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                Label("Kronologi (poin berurutan)")
                entries.forEachIndexed { i, e ->
                    Row(verticalAlignment = Alignment.Top) {
                        Text("${i + 1}.", fontWeight = FontWeight.Bold, color = Ink, modifier = Modifier.padding(top = 18.dp, end = 6.dp))
                        OutlinedTextField(e, { entries[i] = it.take(4000) }, minLines = 2, modifier = Modifier.weight(1f))
                        Column {
                            if (i > 0) TextButton({ entries.add(i - 1, entries.removeAt(i)) }) { Text("▲") }
                            if (entries.size > 1) TextButton({ entries.removeAt(i) }) { Text("×", color = Red) }
                        }
                    }
                }
                if (entries.size < 40) TextButton({ entries += "" }) { Text("+ Poin") }
                error?.let { Text(it, color = Red, fontSize = 14.sp) }
                Button({ save(false) }, Modifier.fillMaxWidth().height(50.dp), enabled = !busy, colors = ButtonDefaults.buttonColors(containerColor = Ink)) { Text("Simpan draft") }
                OutlinedButton({ save(true) }, Modifier.fillMaxWidth(), enabled = !busy) { Text("Simpan & finalisasi") }
                Spacer(Modifier.height(24.dp))
            }
        }
    }
}
