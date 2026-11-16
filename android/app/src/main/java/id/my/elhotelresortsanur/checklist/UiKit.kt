package id.my.elhotelresortsanur.checklist

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.Add
import androidx.compose.material3.ExtendedFloatingActionButton
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.painter.Painter
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp

/* Komponen tampilan bersama supaya semua layar seragam (bilah atas, tombol tambah, keadaan kosong). */

/** Tombol kembali di bilah atas: ikon panah (bukan tulisan). */
@Composable
fun BackButton(onBack: () -> Unit, description: String = "Kembali") =
    IconButton(onClick = onBack) { Icon(Icons.AutoMirrored.Filled.ArrowBack, description, tint = Color.White) }

/** Tombol tambah melayang di kanan bawah (emas, ikon + teks), sama di semua daftar. */
@Composable
fun AddButton(label: String, onClick: () -> Unit) =
    ExtendedFloatingActionButton(
        onClick = onClick, containerColor = Gold, contentColor = Color.White, shape = RoundedCornerShape(16.dp),
        icon = { Icon(Icons.Filled.Add, null) }, text = { Text(label, fontWeight = FontWeight.SemiBold) },
    )

/** Daftar kosong / gagal dimuat: ikon besar + judul + penjelasan. */
@Composable
fun EmptyState(icon: Painter, title: String, text: String? = null) = Column(
    Modifier.fillMaxWidth().padding(horizontal = 32.dp, vertical = 48.dp),
    horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(10.dp),
) {
    Icon(icon, null, tint = Gold.copy(alpha = 0.35f), modifier = Modifier.size(64.dp))
    Text(title, fontWeight = FontWeight.SemiBold, color = Ink, fontSize = 16.sp, textAlign = TextAlign.Center)
    text?.let { Text(it, color = Muted, fontSize = 14.sp, textAlign = TextAlign.Center) }
}

/** Sedang memuat: hanya lingkaran berputar emas di tengah (tanpa tulisan). */
@Composable
fun Loading() = androidx.compose.foundation.layout.Box(Modifier.fillMaxWidth().padding(56.dp), contentAlignment = Alignment.Center) {
    androidx.compose.material3.CircularProgressIndicator(color = Gold, strokeWidth = 3.dp)
}

/** Memuat (berputar) atau gagal dimuat (ikon + petunjuk tarik ke bawah). */
@Composable
fun LoadingOr(refreshing: Boolean) = if (refreshing) Loading() else EmptyState(
    androidx.compose.ui.res.painterResource(R.drawable.ic_offline), "Belum bisa memuat", "Periksa internet, lalu tarik layar ke bawah untuk mencoba lagi.",
)

/** Kelompok isian form dalam kartu putih dengan judul kecil. */
@Composable
fun FormSection(title: String, content: @Composable androidx.compose.foundation.layout.ColumnScope.() -> Unit) = Column(
    Modifier.fillMaxWidth().background(Color.White, RoundedCornerShape(16.dp)).padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp),
) {
    Text(title, fontWeight = FontWeight.SemiBold, color = Ink, fontSize = 15.sp)
    content()
}

/** Pilihan satu dari daftar panjang (mis. departemen) sebagai dropdown, bukan deretan tombol. */
@OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)
@Composable
fun ChoicePicker(label: String, options: List<Pair<Long?, String>>, selected: Long?, onSelect: (Long?) -> Unit) {
    var open by androidx.compose.runtime.remember { androidx.compose.runtime.mutableStateOf(false) }
    androidx.compose.material3.ExposedDropdownMenuBox(open, { open = it }) {
        androidx.compose.material3.OutlinedTextField(
            options.firstOrNull { it.first == selected }?.second ?: "", {}, readOnly = true, label = { Text(label) },
            trailingIcon = { androidx.compose.material3.ExposedDropdownMenuDefaults.TrailingIcon(open) },
            modifier = Modifier.menuAnchor(androidx.compose.material3.MenuAnchorType.PrimaryNotEditable).fillMaxWidth(),
        )
        ExposedDropdownMenu(open, { open = false }) {
            options.forEach { (id, name) -> androidx.compose.material3.DropdownMenuItem({ Text(name) }, { onSelect(id); open = false }) }
        }
    }
}
