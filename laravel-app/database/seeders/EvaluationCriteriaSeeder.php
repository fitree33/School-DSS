<?php

namespace Database\Seeders;

use App\Models\EvaluationCriterion;
use Illuminate\Database\Seeder;

class EvaluationCriteriaSeeder extends Seeder
{
    public function run(): void
    {
        $criteria = [
            ['name' => 'ความสอดคล้องกับนโยบายโรงเรียน', 'description' => 'เป้าหมายของโครงการสอดคล้องกับแผนและพันธกิจของโรงเรียน', 'weight' => 25, 'sort_order' => 10],
            ['name' => 'ความจำเป็นและผลกระทบ', 'description' => 'แก้ปัญหาที่ชัดเจนและสร้างประโยชน์ต่อผู้เรียน ครู หรือโรงเรียน', 'weight' => 25, 'sort_order' => 20],
            ['name' => 'ความเป็นไปได้', 'description' => 'มีเวลา บุคลากร ทรัพยากร และแผนดำเนินงานที่เหมาะสม', 'weight' => 20, 'sort_order' => 30],
            ['name' => 'ความคุ้มค่าของงบประมาณ', 'description' => 'งบประมาณสมเหตุสมผลและสัมพันธ์กับผลลัพธ์ที่คาดหวัง', 'weight' => 20, 'sort_order' => 40],
            ['name' => 'การติดตามและความยั่งยืน', 'description' => 'มีตัวชี้วัด การติดตามผล และแนวทางต่อยอดหลังจบโครงการ', 'weight' => 10, 'sort_order' => 50],
        ];

        foreach ($criteria as $criterion) {
            EvaluationCriterion::updateOrCreate(
                [
                    'evaluation_framework_id' => null,
                    'name' => $criterion['name'],
                ],
                $criterion + ['max_score' => 5, 'is_active' => true]
            );
        }
    }
}
