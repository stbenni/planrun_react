package com.planrun.app

import androidx.activity.result.ActivityResult
import androidx.health.connect.client.HealthConnectClient
import androidx.health.connect.client.PermissionController
import androidx.health.connect.client.permission.HealthPermission
import androidx.health.connect.client.records.DistanceRecord
import androidx.health.connect.client.records.ElevationGainedRecord
import androidx.health.connect.client.records.ExerciseRouteResult
import androidx.health.connect.client.records.ExerciseSessionRecord
import androidx.health.connect.client.records.HeartRateRecord
import androidx.health.connect.client.records.SpeedRecord
import androidx.health.connect.client.records.StepsRecord
import androidx.health.connect.client.records.TotalCaloriesBurnedRecord
import androidx.health.connect.client.request.ReadRecordsRequest
import androidx.health.connect.client.time.TimeRangeFilter
import com.getcapacitor.JSArray
import com.getcapacitor.JSObject
import com.getcapacitor.Plugin
import com.getcapacitor.PluginCall
import com.getcapacitor.PluginMethod
import com.getcapacitor.annotation.ActivityCallback
import com.getcapacitor.annotation.CapacitorPlugin
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import java.time.Instant
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import kotlin.math.floor
import kotlin.math.roundToInt

/**
 * Capacitor-плагин чтения тренировок из Health Connect (read-only).
 * Покрывает часы, которые пишут в Health Connect: Amazfit/Zepp, Samsung, Xiaomi и др.
 *
 * JS API:
 *   HealthConnect.isAvailable() -> { available, status }
 *   HealthConnect.hasPermissions() -> { granted, routeGranted }
 *   HealthConnect.requestPermissions() -> { granted, routeGranted }
 *   HealthConnect.readWorkouts({ since? }) -> { workouts: [...] }  // формат WorkoutService::importWorkouts
 */
@CapacitorPlugin(name = "HealthConnect")
class HealthConnectPlugin : Plugin() {

    private val scope = CoroutineScope(Dispatchers.Main + SupervisorJob())

    // Строка-разрешение на маршруты задаётся литералом, чтобы не зависеть от имени типизированной
    // константы в конкретной версии SDK. ⚠ при апдейте connect-client сверить с HealthPermission.
    private val routePermission = "android.permission.health.READ_EXERCISE_ROUTES"

    private val basePermissions: Set<String> = setOf(
        HealthPermission.getReadPermission(ExerciseSessionRecord::class),
        HealthPermission.getReadPermission(HeartRateRecord::class),
        HealthPermission.getReadPermission(DistanceRecord::class),
        HealthPermission.getReadPermission(SpeedRecord::class),
        HealthPermission.getReadPermission(TotalCaloriesBurnedRecord::class),
        HealthPermission.getReadPermission(StepsRecord::class),
        HealthPermission.getReadPermission(ElevationGainedRecord::class)
    )

    private fun sdkStatus(): Int = try {
        HealthConnectClient.getSdkStatus(context)
    } catch (e: Throwable) {
        HealthConnectClient.SDK_UNAVAILABLE
    }

    private fun client(): HealthConnectClient? = try {
        if (sdkStatus() == HealthConnectClient.SDK_AVAILABLE) HealthConnectClient.getOrCreate(context) else null
    } catch (e: Throwable) {
        null
    }

    @PluginMethod
    fun isAvailable(call: PluginCall) {
        val status = sdkStatus()
        val ret = JSObject()
        ret.put("available", status == HealthConnectClient.SDK_AVAILABLE)
        ret.put(
            "status",
            when (status) {
                HealthConnectClient.SDK_AVAILABLE -> "available"
                HealthConnectClient.SDK_UNAVAILABLE_PROVIDER_UPDATE_REQUIRED -> "update_required"
                else -> "unavailable"
            }
        )
        call.resolve(ret)
    }

    @PluginMethod
    fun hasPermissions(call: PluginCall) {
        val hc = client()
        if (hc == null) {
            call.resolve(JSObject().put("granted", false).put("routeGranted", false))
            return
        }
        scope.launch {
            try {
                val granted = hc.permissionController.getGrantedPermissions()
                call.resolve(
                    JSObject()
                        .put("granted", granted.containsAll(basePermissions))
                        .put("routeGranted", granted.contains(routePermission))
                )
            } catch (e: Throwable) {
                call.reject(e.message ?: "permission check failed")
            }
        }
    }

    @PluginMethod
    fun requestAuthorization(call: PluginCall) {
        if (client() == null) {
            call.reject("Health Connect unavailable")
            return
        }
        // Запрашиваем ТОЛЬКО базовые метрики. READ_EXERCISE_ROUTES в запрос не включаем:
        // для bulk-импорта чужих маршрутов он бесполезен (нужно per-route согласие), а старые
        // версии Health Connect могут отклонить весь запрос из-за незнакомого разрешения.
        val contract = PermissionController.createRequestPermissionResultContract()
        val intent = contract.createIntent(context, basePermissions)
        startActivityForResult(call, intent, "permsResult")
    }

    @ActivityCallback
    private fun permsResult(call: PluginCall?, result: ActivityResult) {
        if (call == null) return
        val hc = client()
        if (hc == null) {
            call.reject("Health Connect unavailable")
            return
        }
        scope.launch {
            try {
                val granted = hc.permissionController.getGrantedPermissions()
                call.resolve(
                    JSObject()
                        .put("granted", granted.containsAll(basePermissions))
                        .put("routeGranted", granted.contains(routePermission))
                )
            } catch (e: Throwable) {
                call.reject(e.message ?: "permission request failed")
            }
        }
    }

    @PluginMethod
    fun readWorkouts(call: PluginCall) {
        val hc = client()
        if (hc == null) {
            call.reject("Health Connect unavailable")
            return
        }
        val sinceIso = call.getString("since")
        val start = parseInstantOr(sinceIso, Instant.now().minusSeconds(THIRTY_DAYS_SEC))
        val end = Instant.now()
        scope.launch {
            try {
                val arr = JSArray()
                readSessions(hc, start, end).forEach { arr.put(it) }
                call.resolve(JSObject().put("workouts", arr))
            } catch (e: Throwable) {
                call.reject(e.message ?: "read failed")
            }
        }
    }

    private suspend fun readSessions(hc: HealthConnectClient, start: Instant, end: Instant): List<JSObject> {
        val sessions = hc.readRecords(ReadRecordsRequest(ExerciseSessionRecord::class, TimeRangeFilter.between(start, end))).records
        // Один забег часто пишут ДВА источника (часы без GPS + Google Fit с GPS).
        // Группируем по времени старта и склеиваем в одну тренировку (с лучшими данными).
        val groups = LinkedHashMap<Long, MutableList<ExerciseSessionRecord>>()
        for (s in sessions) {
            groups.getOrPut(s.startTime.epochSecond) { ArrayList() }.add(s)
        }
        val out = ArrayList<JSObject>(groups.size)
        for ((_, group) in groups) {
            buildMergedWorkout(hc, group)?.let { out.add(it) }
        }
        return out
    }

    private class SessionMetrics(
        val session: ExerciseSessionRecord,
        val distanceM: Double,
        val calories: Double,
        val elevationM: Double,
        val hrSamples: List<HeartRateRecord.Sample>,
        val hasRoute: Boolean
    )

    private suspend fun collectMetrics(hc: HealthConnectClient, s: ExerciseSessionRecord): SessionMetrics {
        val sFilter = TimeRangeFilter.between(s.startTime, s.endTime)
        // фильтр по источнику самой сессии — иначе сумма дистанции из нескольких источников даёт ×2
        val origin = setOf(s.metadata.dataOrigin)
        val distanceM = sumOrZero { hc.readRecords(ReadRecordsRequest(DistanceRecord::class, sFilter, dataOriginFilter = origin)).records.sumOf { it.distance.inMeters } }
        val calories = sumOrZero { hc.readRecords(ReadRecordsRequest(TotalCaloriesBurnedRecord::class, sFilter, dataOriginFilter = origin)).records.sumOf { it.energy.inKilocalories } }
        val elevationM = sumOrZero { hc.readRecords(ReadRecordsRequest(ElevationGainedRecord::class, sFilter, dataOriginFilter = origin)).records.sumOf { it.elevation.inMeters } }
        val hrSamples = try {
            hc.readRecords(ReadRecordsRequest(HeartRateRecord::class, sFilter, dataOriginFilter = origin)).records.flatMap { it.samples }
        } catch (e: Throwable) {
            emptyList()
        }
        val rr = s.exerciseRouteResult
        val hasRoute = rr is ExerciseRouteResult.Data && rr.exerciseRoute.route.isNotEmpty()
        return SessionMetrics(s, distanceM, calories, elevationM, hrSamples, hasRoute)
    }

    /** Склейка дубль-сессий одного забега: предпочитаем источник с маршрутом, пульс — самую полную серию. */
    private suspend fun buildMergedWorkout(hc: HealthConnectClient, group: List<ExerciseSessionRecord>): JSObject? {
        if (group.isEmpty()) return null
        val metrics = group.map { collectMetrics(hc, it) }

        // primary: источник С МАРШРУТОМ (его дистанция = GPS-точная), иначе с макс. дистанцией, иначе первый
        val routeMetric = metrics.firstOrNull { it.hasRoute }
        val primary = routeMetric ?: metrics.maxByOrNull { it.distanceM } ?: metrics.first()
        val s = primary.session

        var distanceM = primary.distanceM
        if (distanceM <= 0) distanceM = metrics.maxOf { it.distanceM }
        val calories = if (primary.calories > 0) primary.calories else metrics.maxOf { it.calories }
        val elevationM = if (primary.elevationM > 0) primary.elevationM else metrics.maxOf { it.elevationM }
        val hrSamples = metrics.maxByOrNull { it.hrSamples.size }?.hrSamples ?: emptyList()
        val avgHr = if (hrSamples.isNotEmpty()) hrSamples.map { it.beatsPerMinute }.average().roundToInt() else 0
        val maxHr = if (hrSamples.isNotEmpty()) hrSamples.maxOf { it.beatsPerMinute }.toInt() else 0

        val durationSec = (s.endTime.epochSecond - s.startTime.epochSecond).coerceAtLeast(0)
        val activityType = mapExerciseType(s.exerciseType)
        val zone = s.startZoneOffset?.let { ZoneId.ofOffset("", it) }

        val w = JSObject()
        w.put("activity_type", activityType)
        w.put("start_time", formatLocal(s.startTime, zone))
        w.put("end_time", formatLocal(s.endTime, s.endZoneOffset?.let { ZoneId.ofOffset("", it) }))
        w.put("duration_seconds", durationSec.toInt())
        w.put("duration_minutes", (durationSec / 60.0).roundToInt())
        if (distanceM > 0) w.put("distance_km", round3(distanceM / 1000.0))
        paceFromDistanceDuration(distanceM, durationSec, activityType)?.let { w.put("avg_pace", it) }
        if (avgHr > 0) w.put("avg_heart_rate", avgHr)
        if (maxHr > 0) w.put("max_heart_rate", maxHr)
        if (elevationM > 0) w.put("elevation_gain", elevationM.roundToInt())
        if (calories > 0) w.put("calories", calories.roundToInt())
        // Стабильный id по времени старта — не зависит от того, какой источник стал primary,
        // поэтому повторный синк (после выдачи прав на маршрут) ОБНОВИТ запись и докинет трек.
        w.put("external_id", "hc_" + s.startTime.epochSecond)

        // timeline: пульс + GPS (трек берём из источника, где он есть)
        val timeline = buildTimeline(routeMetric?.session, hrSamples, zone)
        if (timeline.length() > 0) w.put("timeline", timeline)

        return w
    }

    /**
     * timeline = пульсовая серия (для зон/графиков) + GPS-точки, смёрженные по времени.
     * Базой берём пульс (он плотный и нужен для зон); GPS подмешиваем по совпадению секунды (±1с).
     * Если пульса нет — отдаём чистый GPS-трек. Если ничего нет — пустой массив.
     *
     * ⚠ connect-client 1.1.0: ExerciseSessionRecord.exerciseRouteResult: ExerciseRouteResult;
     * GPS доступен только при выданном READ_EXERCISE_ROUTES и если приложение записало трек в HC
     * (многие часы, напр. Amazfit/Zepp, пишут дистанцию/пульс, но не GPS-трек).
     */
    private fun buildTimeline(routeSession: ExerciseSessionRecord?, hrSamples: List<HeartRateRecord.Sample>, zone: ZoneId?): JSArray {
        // GPS-точки, индексированные по epoch-секунде: [lat, lng, alt(NaN если нет)]
        val routeBySec = HashMap<Long, DoubleArray>()
        val rr = routeSession?.exerciseRouteResult
        if (rr is ExerciseRouteResult.Data) {
            for (loc in rr.exerciseRoute.route) {
                routeBySec[loc.time.epochSecond] = doubleArrayOf(loc.latitude, loc.longitude, loc.altitude?.inMeters ?: Double.NaN)
            }
        }

        val arr = JSArray()

        if (hrSamples.isNotEmpty()) {
            val sorted = hrSamples.sortedBy { it.time.epochSecond }
            val step = maxOf(1, sorted.size / 500)
            var i = 0
            while (i < sorted.size) {
                val sample = sorted[i]
                val p = JSObject()
                p.put("timestamp", formatLocal(sample.time, zone))
                p.put("heart_rate", sample.beatsPerMinute.toInt())
                val sec = sample.time.epochSecond
                val loc = routeBySec[sec] ?: routeBySec[sec - 1] ?: routeBySec[sec + 1]
                if (loc != null) {
                    p.put("latitude", loc[0])
                    p.put("longitude", loc[1])
                    if (!loc[2].isNaN()) p.put("altitude", loc[2])
                }
                arr.put(p)
                i += step
            }
            return arr
        }

        if (routeBySec.isNotEmpty()) {
            val secs = routeBySec.keys.sorted()
            val step = maxOf(1, secs.size / 500)
            var i = 0
            while (i < secs.size) {
                val sec = secs[i]
                val loc = routeBySec[sec]!!
                val p = JSObject()
                p.put("timestamp", formatLocal(Instant.ofEpochSecond(sec), zone))
                p.put("latitude", loc[0])
                p.put("longitude", loc[1])
                if (!loc[2].isNaN()) p.put("altitude", loc[2])
                arr.put(p)
                i += step
            }
        }
        return arr
    }

    private inline fun sumOrZero(block: () -> Double): Double = try {
        block()
    } catch (e: Throwable) {
        0.0
    }

    private fun parseInstantOr(iso: String?, fallback: Instant): Instant = try {
        if (iso.isNullOrBlank()) fallback else Instant.parse(iso)
    } catch (e: Throwable) {
        fallback
    }

    private fun formatLocal(instant: Instant, zone: ZoneId?): String =
        LOCAL_FMT.withZone(zone ?: ZoneId.systemDefault()).format(instant)

    private fun round3(v: Double): Double = (v * 1000.0).roundToInt() / 1000.0

    private fun paceFromDistanceDuration(distanceM: Double, durationSec: Long, activityType: String): String? {
        if (distanceM <= 0 || durationSec <= 0) return null
        if (activityType != "running" && activityType != "walking" && activityType != "hiking") return null
        val secPerKm = durationSec / (distanceM / 1000.0)
        val min = floor(secPerKm / 60).toInt()
        val sec = (secPerKm % 60).roundToInt()
        return String.format("%d:%02d", min, sec)
    }

    private fun mapExerciseType(type: Int): String = when (type) {
        ExerciseSessionRecord.EXERCISE_TYPE_RUNNING,
        ExerciseSessionRecord.EXERCISE_TYPE_RUNNING_TREADMILL -> "running"
        ExerciseSessionRecord.EXERCISE_TYPE_WALKING -> "walking"
        ExerciseSessionRecord.EXERCISE_TYPE_HIKING -> "hiking"
        ExerciseSessionRecord.EXERCISE_TYPE_BIKING,
        ExerciseSessionRecord.EXERCISE_TYPE_BIKING_STATIONARY -> "cycling"
        ExerciseSessionRecord.EXERCISE_TYPE_SWIMMING_POOL,
        ExerciseSessionRecord.EXERCISE_TYPE_SWIMMING_OPEN_WATER -> "swimming"
        // Силовая, HIIT, кросс-тренинг и прочее → "other" (в приложении = ОФП, иконка-гантеля).
        // НЕ "running" — иначе беговая статистика засоряется не-беговыми сессиями.
        else -> "other"
    }

    companion object {
        private const val THIRTY_DAYS_SEC = 60L * 60 * 24 * 30
        private val LOCAL_FMT: DateTimeFormatter = DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm:ss")
    }
}
