<?php

namespace App\Http\Controllers;
use App\Models\User;
use Illuminate\Http\Request;

use App\Models\Attendance;
use Carbon\Carbon;
use App\Http\Controllers\AttendanceController;
use Symfony\Component\HttpFoundation\StreamedResponse;



class StaffController extends Controller
{
    public function staffList()
    {
        $users = User::all();

        $userData = $users->map(function($user) {
            return [
                'user' => $user,
                'detail_url' => route('admin.attendance.staff', ['id' => $user->id]),
            ];
        });

        return view('admin.staff_index', compact('userData'));
    }



public function staffMonth($id, Request $request)
{
    $user = User::findOrFail($id);

    $targetMonth = $request->input('month') ?? now()->format('Y-m');
    $date = Carbon::parse($targetMonth . '-01');

    $startOfMonth = $date->copy()->startOfMonth();
    $endOfMonth = $date->copy()->endOfMonth();

    $prevMonth = $startOfMonth->copy()->subMonth()->format('Y-m');
    $nextMonth = $startOfMonth->copy()->addMonth()->format('Y-m');
    $displayMonth = $startOfMonth->format('Y年n月');

    // 👇 正しいカラム名で取得 & キー設定
    $attendancesRaw = Attendance::where('user_id', $id)
        ->whereBetween('work_date', [$startOfMonth, $endOfMonth])
        ->get()
        ->keyBy(function ($item) {
            return Carbon::parse($item->work_date)->format('Y-m-d');
        });

    $attendances = [];

    for ($date = $startOfMonth->copy(); $date->lte($endOfMonth); $date->addDay()) {
        $formattedDate = $date->format('Y-m-d');

        $weekdays = ['日', '月', '火', '水', '木', '金', '土'];
        $weekday = $weekdays[$date->dayOfWeek];

        $attendance = $attendancesRaw->get($formattedDate);

        if ($attendance && optional($attendance->attendanceRequest)->request_status === 'pending') {
            // 勤怠はあるけど "申請中" の状態
            $attendances[] = [
                'date'       => $formattedDate,
                'weekday'    => $weekday,
                'start_time' => '申請中',
                'end_time'   => '申請中',
                'break_time' => '申請中',
                'work_time'  => '申請中',
                'detail_url' => route('attendance.detailByRole', ['id' => $attendance->id]),
            ];
        } else {
            // 通常の勤務データあり
            $attendances[] = [
                'date'       => $formattedDate,
                'weekday'    => $weekday,
                'start_time' => $attendance ? Carbon::parse($attendance->shift_start)->format('H:i') : '',
                'end_time'   => $attendance ? Carbon::parse($attendance->shift_end)->format('H:i') : '',
                'break_time' => $attendance ? gmdate('H:i', $attendance->break_minutes * 60) : '',
                'work_time'  => $attendance ? gmdate('H:i', $attendance->total_work_minutes * 60) : '',
                'detail_url' => $attendance
                            ? route('attendance.detailByRole', ['id' => $attendance->id])
                            : route('attendance.create', ['user_id' => $user->id, 'date' => $formattedDate]),
            ];
        }
    }

    return view('admin.staff_attendance', [
        'user' => $user,
        'attendances' => $attendances,
        'prevMonth' => $prevMonth,
        'nextMonth' => $nextMonth,
        'displayMonth' => $displayMonth,
    ]);
}


//CSV出力
public function exportCsv(Request $request)
{
    $attendanceDataJson = $request->input('attendanceData');
    $attendanceData = json_decode($attendanceDataJson, true);

    // 必要ならアプリのロケールが 'ja' になっていることを確認
    Carbon::setLocale('ja');

    $response = new StreamedResponse(function() use ($attendanceData) {
        $handle = fopen('php://output', 'w');
        stream_filter_prepend($handle, 'convert.iconv.utf-8/cp932//TRANSLIT');

        // ご指定のヘッダー
        fputcsv($handle, ['日付', '出勤', '退勤', '休憩', '合計']);

        foreach ($attendanceData as $row) {
            // $row['date']が "YYYY/MM/DD" or "YYYY-MM-DD" で入っている想定
            $dateObj = Carbon::parse($row['date']);
            // ●月●日（曜）形式へ
            $formattedDate = $dateObj->isoFormat('M月D日（ddd）');

            fputcsv($handle, [
                $formattedDate,
                $row['start_time'],
                $row['end_time'],
                $row['break_time'],
                $row['work_time'],
            ]);
        }

        fclose($handle);
    });

    $filename = '勤怠一覧_' . date('Ym') . '.csv';

    $response->headers->set('Content-Type', 'text/csv; charset=Shift-JIS');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
    $response->headers->set('Content-Transfer-Encoding', 'binary');

    return $response;
}

}
