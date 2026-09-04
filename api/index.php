<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../database/install.php';

$path = trim((string)($_GET['action'] ?? ''), '/');
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

function validateEmail(string $email): bool { return filter_var($email,FILTER_VALIDATE_EMAIL)!==false; }
function userPayload(array $u): array { return ['id'=>(int)$u['id'],'name'=>$u['full_name'],'email'=>$u['email'],'phone'=>$u['phone'],'role'=>$u['role'],'student_id'=>$u['student_id'],'librarian_id'=>$u['librarian_id']??null,'member_type'=>$u['member_type']??'Student','address'=>$u['address']??null]; }
function uniqueLibrarianId(PDO $pdo): string {
    do { $id='LIB-'.date('Y').'-'.str_pad((string)random_int(1,99999),5,'0',STR_PAD_LEFT); $s=$pdo->prepare('SELECT id FROM users WHERE librarian_id=?'); $s->execute([$id]); } while($s->fetch());
    return $id;
}
function uniqueStudentId(PDO $pdo): string {
    do { $id='STU-'.date('Y').'-'.str_pad((string)random_int(1,99999),5,'0',STR_PAD_LEFT); $s=$pdo->prepare('SELECT id FROM users WHERE student_id=?'); $s->execute([$id]); } while($s->fetch());
    return $id;
}
function findOrCreateAuthor(PDO $pdo,string $name): int { $s=$pdo->prepare('SELECT id FROM authors WHERE name=?');$s->execute([$name]);$r=$s->fetch();if($r)return(int)$r['id'];$s=$pdo->prepare('INSERT INTO authors(name) VALUES(?)');$s->execute([$name]);return(int)$pdo->lastInsertId(); }
function findOrCreateCategory(PDO $pdo,string $name): int { $s=$pdo->prepare('SELECT id FROM categories WHERE name=?');$s->execute([$name]);$r=$s->fetch();if($r)return(int)$r['id'];$s=$pdo->prepare('INSERT INTO categories(name) VALUES(?)');$s->execute([$name]);return(int)$pdo->lastInsertId(); }

function librarySettings(PDO $pdo): array { $r=$pdo->query('SELECT * FROM library_settings WHERE id=1')->fetch(); return $r ?: ['loan_days'=>LOAN_DAYS,'fine_per_day'=>FINE_PER_DAY]; }

try {
 switch($path) {
  case 'csrf': jsonResponse(['ok'=>true,'token'=>csrfToken()]);
  case 'diagnostics':
    $pdo = db();
    $checks = [];
    foreach (['users','authors','categories','books','book_copies','issues','returns_log','fines','reservations','audit_logs','library_settings'] as $table) {
        $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=? AND table_name=?');
        $q->execute([DB_NAME, $table]);
        $checks[$table] = (bool)$q->fetchColumn();
    }
    jsonResponse(['ok'=>true,'app'=>APP_NAME,'database'=>DB_NAME,'tables'=>$checks]);
  case 'setup-status':
    $pdo=db(); $has=(int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='assistant' AND status='active'")->fetchColumn()>0;
    jsonResponse(['ok'=>true,'has_librarian'=>$has]);
  case 'session': jsonResponse(['ok'=>true,'user'=>currentUser()]);
  case 'register':
    if($method!=='POST') jsonResponse(['ok'=>false,'message'=>'Method not allowed'],405);
    requireCsrf(); $d=requestJson();
    $name=clean((string)($d['name']??''));$email=strtolower(clean((string)($d['email']??'')));$phone=clean((string)($d['phone']??''));$pass=(string)($d['password']??'');
    if(mb_strlen($name)<2||!validateEmail($email)||mb_strlen($pass)<8) jsonResponse(['ok'=>false,'message'=>'Enter a valid name, email and password of at least 8 characters.'],422);
    $pdo=db();$s=$pdo->prepare('SELECT id FROM users WHERE email=?');$s->execute([$email]);if($s->fetch())jsonResponse(['ok'=>false,'message'=>'An account with this email already exists.'],409);
    $sid=uniqueStudentId($pdo);$hash=password_hash($pass,PASSWORD_DEFAULT);$s=$pdo->prepare("INSERT INTO users(full_name,email,phone,password_hash,role,student_id,member_type,address) VALUES(?,?,?,?, 'student',?,?,?)");$s->execute([$name,$email,$phone?:null,$hash,$sid,'Student',null]);$id=(int)$pdo->lastInsertId();
    $u=['id'=>$id,'full_name'=>$name,'email'=>$email,'phone'=>$phone,'role'=>'student','student_id'=>$sid];session_regenerate_id(true);$_SESSION['user']=userPayload($u);logAction($id,'REGISTER','user',$id,'student');jsonResponse(['ok'=>true,'message'=>'Member account created successfully.','user'=>$_SESSION['user']]);
  case 'initial-librarian':
    if($method!=='POST') jsonResponse(['ok'=>false,'message'=>'Method not allowed'],405); requireCsrf();
    $pdo=db(); if((int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='assistant' AND status='active'")->fetchColumn()>0) jsonResponse(['ok'=>false,'message'=>'An active Librarian already exists. Sign in to add another Librarian.'],409);
    $d=requestJson();$name=clean((string)($d['name']??''));$email=strtolower(clean((string)($d['email']??'')));$phone=clean((string)($d['phone']??''));$pass=(string)($d['password']??'');
    if(mb_strlen($name)<2||!validateEmail($email)||mb_strlen($pass)<8) jsonResponse(['ok'=>false,'message'=>'Enter valid Librarian details and a password of at least 8 characters.'],422);
    $s=$pdo->prepare('SELECT id FROM users WHERE email=?');$s->execute([$email]);if($s->fetch())jsonResponse(['ok'=>false,'message'=>'An account with this email already exists.'],409);
    $lid=uniqueLibrarianId($pdo);$hash=password_hash($pass,PASSWORD_DEFAULT);$s=$pdo->prepare("INSERT INTO users(full_name,email,phone,password_hash,role,librarian_id,student_id,member_type) VALUES(?,?,?,?, 'assistant',?,NULL,'Other')");$s->execute([$name,$email,$phone?:null,$hash,$lid]);$id=(int)$pdo->lastInsertId();
    logAction($id,'CREATE','librarian',$id,$lid);jsonResponse(['ok'=>true,'message'=>"First Librarian created. Librarian ID: $lid",'librarian_id'=>$lid]);
  case 'librarians':
    $u=requireLogin('assistant'); $rows=db()->query("SELECT id,full_name,email,phone,librarian_id,status,created_at FROM users WHERE role='assistant' ORDER BY id ASC")->fetchAll(); jsonResponse(['ok'=>true,'librarians'=>$rows]);
  case 'librarian-create':
    $u=requireLogin('assistant');requireCsrf();$d=requestJson();$name=clean((string)($d['name']??''));$email=strtolower(clean((string)($d['email']??'')));$phone=clean((string)($d['phone']??''));$pass=(string)($d['password']??'');
    if(mb_strlen($name)<2||!validateEmail($email)||mb_strlen($pass)<8) jsonResponse(['ok'=>false,'message'=>'Enter valid Librarian details and a password of at least 8 characters.'],422);
    $pdo=db();$st=$pdo->prepare('SELECT id FROM users WHERE email=?');$st->execute([$email]);if($st->fetch())jsonResponse(['ok'=>false,'message'=>'Email already exists.'],409);
    $lid=uniqueLibrarianId($pdo);$st=$pdo->prepare("INSERT INTO users(full_name,email,phone,password_hash,role,librarian_id,student_id,member_type) VALUES(?,?,?,?, 'assistant',?,NULL,'Other')");$st->execute([$name,$email,$phone?:null,password_hash($pass,PASSWORD_DEFAULT),$lid]);$id=(int)$pdo->lastInsertId();logAction((int)$u['id'],'CREATE','librarian',$id,$lid);jsonResponse(['ok'=>true,'message'=>"Librarian created successfully. ID: $lid",'librarian_id'=>$lid]);
  case 'librarian-action':
    $u=requireLogin('assistant');requireCsrf();$d=requestJson();$id=(int)($d['id']??0);$action=(string)($d['action']??'');if($id===(int)$u['id'])jsonResponse(['ok'=>false,'message'=>'You cannot deactivate your own account.'],422);if(!in_array($action,['activate','deactivate'],true))jsonResponse(['ok'=>false,'message'=>'Invalid action.'],422);$status=$action==='activate'?'active':'blocked';$st=db()->prepare("UPDATE users SET status=? WHERE id=? AND role='assistant'");$st->execute([$status,$id]);if(!$st->rowCount())jsonResponse(['ok'=>false,'message'=>'Librarian not found or unchanged.'],404);logAction((int)$u['id'],strtoupper($action),'librarian',$id);jsonResponse(['ok'=>true,'message'=>'Librarian status updated.']);
  case 'login':
  case 'member-login':
    if($method!=='POST')jsonResponse(['ok'=>false,'message'=>'Method not allowed'],405);requireCsrf();$d=requestJson();$email=strtolower(clean((string)($d['email']??'')));$pass=(string)($d['password']??'');$s=db()->prepare("SELECT * FROM users WHERE email=? AND status='active' LIMIT 1");$s->execute([$email]);$u=$s->fetch();if(!$u||!password_verify($pass,$u['password_hash']))jsonResponse(['ok'=>false,'message'=>'Incorrect email or password.'],401);if($path==='login' && $u['role']!=='assistant')jsonResponse(['ok'=>false,'message'=>'This sign-in is for Librarians. Please use Member Sign In.'],403);if($path==='member-login' && $u['role']!=='student')jsonResponse(['ok'=>false,'message'=>'This sign-in is for Members. Please use Librarian Sign In.'],403);session_regenerate_id(true);$_SESSION['user']=userPayload($u);logAction((int)$u['id'],'LOGIN','user',(int)$u['id']);jsonResponse(['ok'=>true,'user'=>$_SESSION['user']]);
  case 'logout': requireCsrf(); if($u=currentUser())logAction((int)$u['id'],'LOGOUT','user',(int)$u['id']);$_SESSION=[];session_destroy();jsonResponse(['ok'=>true]);
  case 'delete-account': requireCsrf();$u=requireLogin();$pdo=db();$s=$pdo->prepare("UPDATE users SET status='deleted',email=CONCAT('deleted+',id,'@invalid.local') WHERE id=?");$s->execute([(int)$u['id']]);session_destroy();jsonResponse(['ok'=>true,'message'=>'Your account has been deleted.']);
  case 'change-password':
    $u=requireLogin();requireCsrf();$d=requestJson();
     $old=(string)($d['current_password']??$d['old_password']??$d['currentPassword']??'');
     $new=(string)($d['new_password']??$d['newPassword']??'');
     $confirm=array_key_exists('confirm_password',$d)?(string)$d['confirm_password']:(array_key_exists('confirmPassword',$d)?(string)$d['confirmPassword']:null);
     if($old==='')jsonResponse(['ok'=>false,'message'=>'Current password is required.'],422);
     if($new==='')jsonResponse(['ok'=>false,'message'=>'New password is required.'],422);
     if(mb_strlen($new)<8)jsonResponse(['ok'=>false,'message'=>'New password must be at least 8 characters.'],422);
     if($confirm!==null && $new!==$confirm)jsonResponse(['ok'=>false,'message'=>'New password and confirmation password do not match.'],422);
     $st=db()->prepare('SELECT password_hash FROM users WHERE id=? AND status="active"');
     $st->execute([(int)$u['id']]);
     $row=$st->fetch();
     if(!$row||!password_verify($old,$row['password_hash']))jsonResponse(['ok'=>false,'message'=>'Current password is incorrect.'],401);
     if(password_verify($new,$row['password_hash']))jsonResponse(['ok'=>false,'message'=>'New password must be different from the current password.'],422);
     db()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($new,PASSWORD_DEFAULT),(int)$u['id']]);
     jsonResponse(['ok'=>true,'message'=>'Password changed successfully.']);
  case 'profile':
    $u=requireLogin(); if($method==='GET'){$s=db()->prepare('SELECT id,full_name,email,phone,role,student_id,member_type,address,status,created_at FROM users WHERE id=?');$s->execute([(int)$u['id']]);jsonResponse(['ok'=>true,'user'=>$s->fetch()]);}
    requireCsrf();$d=requestJson();
     $name=clean((string)($d['name']??$d['full_name']??$d['nameInput']??''));
     $phone=clean((string)($d['phone']??''));
     $address=clean((string)($d['address']??''));
     if(mb_strlen($name)<2)jsonResponse(['ok'=>false,'message'=>'Name is required.'],422);
     $s=db()->prepare('UPDATE users SET full_name=?,phone=?,address=? WHERE id=?');
     $s->execute([$name,$phone?:null,$address?:null,(int)$u['id']]);
     $_SESSION['user']['name']=$name;
     $_SESSION['user']['phone']=$phone;
     $_SESSION['user']['address']=$address;
     jsonResponse(['ok'=>true,'message'=>'Profile updated successfully.','user'=>$_SESSION['user']]);
  case 'books':
    requireLogin();$pdo=db();$q=clean((string)($_GET['q']??''));$sql="SELECT b.id,b.isbn,b.title,b.publisher,b.published_year,b.description,b.cover_url,a.name author,c.name category,COUNT(bc.id) total_copies,SUM(bc.status='available') available_copies FROM books b JOIN authors a ON a.id=b.author_id JOIN categories c ON c.id=b.category_id LEFT JOIN book_copies bc ON bc.book_id=b.id WHERE 1=1";$args=[];if($q!==''){$sql.=' AND (b.title LIKE ? OR a.name LIKE ? OR c.name LIKE ? OR b.isbn LIKE ?)';$like="%$q%";$args=[$like,$like,$like,$like];}$sql.=' GROUP BY b.id ORDER BY b.created_at DESC';$s=$pdo->prepare($sql);$s->execute($args);jsonResponse(['ok'=>true,'books'=>$s->fetchAll()]);
  case 'authors': requireLogin('assistant'); $s=db()->query("SELECT a.*,COUNT(b.id) book_count FROM authors a LEFT JOIN books b ON b.author_id=a.id GROUP BY a.id ORDER BY a.name");jsonResponse(['ok'=>true,'authors'=>$s->fetchAll()]);
  case 'categories': requireLogin('assistant'); $s=db()->query("SELECT c.*,COUNT(b.id) book_count FROM categories c LEFT JOIN books b ON b.category_id=c.id GROUP BY c.id ORDER BY c.name");jsonResponse(['ok'=>true,'categories'=>$s->fetchAll()]);
  case 'members':
  case 'students': requireLogin('assistant'); $q=clean((string)($_GET['q']??''));$s=db();$sql="SELECT id,full_name,email,phone,student_id,member_type,address,status,created_at FROM users WHERE role='student' AND status='active'";$args=[];if($q){$sql.=' AND (full_name LIKE ? OR student_id LIKE ? OR email LIKE ?)';$like="%$q%";$args=[$like,$like,$like];}$sql.=' ORDER BY full_name';$st=$s->prepare($sql);$st->execute($args);$rows=$st->fetchAll();jsonResponse(['ok'=>true,'students'=>$rows,'members'=>$rows]);
  case 'add-book':
    $u=requireLogin('assistant');requireCsrf();$d=requestJson();$title=clean((string)($d['title']??''));$author=clean((string)($d['author']??''));$cat=clean((string)($d['category']??''));$copies=max(1,min(100,(int)($d['copies']??1)));if(!$title||!$author||!$cat)jsonResponse(['ok'=>false,'message'=>'Title, author and category are required.'],422);$pdo=db();$cfg=librarySettings($pdo);$pdo->beginTransaction();try{$aid=findOrCreateAuthor($pdo,$author);$cid=findOrCreateCategory($pdo,$cat);$isbn=clean((string)($d['isbn']??''));$s=$pdo->prepare('INSERT INTO books(isbn,title,author_id,category_id,publisher,published_year,description,cover_url) VALUES(?,?,?,?,?,?,?,?)');$s->execute([$isbn?:null,$title,$aid,$cid,clean((string)($d['publisher']??''))?:null,(int)($d['year']??0)?:null,clean((string)($d['description']??''))?:null,clean((string)($d['cover_url']??''))?:null]);$bookId=(int)$pdo->lastInsertId();$s=$pdo->prepare('INSERT INTO book_copies(book_id,accession_no,barcode,shelf_location) VALUES(?,?,?,?)');for($i=1;$i<=$copies;$i++){$acc='SH-'.date('Y').'-'.str_pad((string)$bookId,5,'0',STR_PAD_LEFT).'-'.str_pad((string)$i,3,'0',STR_PAD_LEFT);$s->execute([$bookId,$acc,'BC-'.$acc,clean((string)($d['shelf']??'A-01'))]);}$pdo->commit();logAction((int)$u['id'],'CREATE','book',$bookId,$title);jsonResponse(['ok'=>true,'message'=>"Book added with $copies physical cop".($copies===1?'y':'ies').'.']);}catch(Throwable $e){$pdo->rollBack();throw $e;}
  case 'book-details':
    requireLogin();$id=(int)($_GET['id']??0);if($id<=0)jsonResponse(['ok'=>false,'message'=>'Invalid book ID.'],422);$pdo=db();$st=$pdo->prepare('SELECT b.*,a.name author,c.name category FROM books b JOIN authors a ON a.id=b.author_id JOIN categories c ON c.id=b.category_id WHERE b.id=?');$st->execute([$id]);$book=$st->fetch();if(!$book)jsonResponse(['ok'=>false,'message'=>'Book not found.'],404);$st=$pdo->prepare('SELECT id,accession_no,barcode,status,shelf_location FROM book_copies WHERE book_id=? ORDER BY id');$st->execute([$id]);jsonResponse(['ok'=>true,'book'=>$book,'copies'=>$st->fetchAll()]);
  case 'update-book':
    $u=requireLogin('assistant');requireCsrf();$d=requestJson();$id=(int)($d['id']??0);$title=clean((string)($d['title']??''));$author=clean((string)($d['author']??''));$cat=clean((string)($d['category']??''));if($id<=0||!$title||!$author||!$cat)jsonResponse(['ok'=>false,'message'=>'Book, author and category are required.'],422);$pdo=db();$aid=findOrCreateAuthor($pdo,$author);$cid=findOrCreateCategory($pdo,$cat);$st=$pdo->prepare('UPDATE books SET isbn=?,title=?,author_id=?,category_id=?,publisher=?,published_year=?,description=?,cover_url=? WHERE id=?');$st->execute([clean((string)($d['isbn']??''))?:null,$title,$aid,$cid,clean((string)($d['publisher']??''))?:null,(int)($d['year']??0)?:null,clean((string)($d['description']??''))?:null,clean((string)($d['cover_url']??''))?:null,$id]);$st=$pdo->prepare('UPDATE book_copies SET shelf_location=? WHERE book_id=?');$st->execute([clean((string)($d['shelf']??''))?:null,$id]);logAction((int)$u['id'],'UPDATE','book',$id,$title);jsonResponse(['ok'=>true,'message'=>'Book details updated.']);
  case 'delete-book':
    $u=requireLogin('assistant');requireCsrf();$id=(int)($_GET['id']??0);
    if($id<=0) jsonResponse(['ok'=>false,'message'=>'Invalid book ID.'],422);
    $pdo=db();
    $s=$pdo->prepare('SELECT id FROM books WHERE id=?');$s->execute([$id]);if(!$s->fetch())jsonResponse(['ok'=>false,'message'=>'Book not found.'],404);
    $s=$pdo->prepare("SELECT COUNT(*) n FROM book_copies WHERE book_id=? AND status IN ('issued','reserved')");$s->execute([$id]);if((int)$s->fetch()['n']>0)jsonResponse(['ok'=>false,'message'=>'This book cannot be removed while copies are issued or reserved.'],409);
    $s=$pdo->prepare('SELECT COUNT(*) n FROM issues i JOIN book_copies bc ON bc.id=i.copy_id WHERE bc.book_id=?');$s->execute([$id]);if((int)$s->fetch()['n']>0)jsonResponse(['ok'=>false,'message'=>'This title has issue history and cannot be permanently removed. Keep it for library records.'],409);
    $pdo->prepare('DELETE FROM books WHERE id=?')->execute([$id]);logAction((int)$u['id'],'DELETE','book',$id);jsonResponse(['ok'=>true,'message'=>'Book removed.']);
  case 'issue':
    $u=requireLogin('assistant');requireCsrf();$d=requestJson();$student=(int)($d['student_id']??0);$book=(int)($d['book_id']??0);$pdo=db();$cfg=librarySettings($pdo);$pdo->beginTransaction();try{$s=$pdo->prepare("SELECT id FROM users WHERE id=? AND role='student' AND status='active'");$s->execute([$student]);if(!$s->fetch())throw new RuntimeException('Student not found.');$s=$pdo->prepare("SELECT i.id FROM issues i JOIN book_copies bc ON bc.id=i.copy_id WHERE i.student_id=? AND bc.book_id=? AND i.returned_at IS NULL LIMIT 1");$s->execute([$student,$book]);if($s->fetch())throw new RuntimeException('This student already has this title issued.');$s=$pdo->prepare("SELECT id FROM book_copies WHERE book_id=? AND status='available' ORDER BY id LIMIT 1 FOR UPDATE");$s->execute([$book]);$copy=$s->fetch();if(!$copy)throw new RuntimeException('No available physical copy for this book.');$issue=date('Y-m-d');$requestedDue=clean((string)($d['due_date']??''));$due=$requestedDue && preg_match('/^\d{4}-\d{2}-\d{2}$/',$requestedDue)?$requestedDue:date('Y-m-d',strtotime('+'.((int)$cfg['loan_days']).' days'));if(strtotime($due)<strtotime($issue))throw new RuntimeException('Due date cannot be before issue date.');$s=$pdo->prepare('INSERT INTO issues(copy_id,student_id,issued_by,issue_date,due_date) VALUES(?,?,?,?,?)');$s->execute([(int)$copy['id'],$student,(int)$u['id'],$issue,$due]);$iid=(int)$pdo->lastInsertId();$pdo->prepare("UPDATE book_copies SET status='issued' WHERE id=?")->execute([(int)$copy['id']]);$pdo->commit();logAction((int)$u['id'],'ISSUE','issue',$iid,'Student '.$student.' / Book '.$book);jsonResponse(['ok'=>true,'message'=>"Book issued successfully. Due date: $due",'due_date'=>$due]);}catch(Throwable $e){$pdo->rollBack();jsonResponse(['ok'=>false,'message'=>$e->getMessage()],422);}
  case 'my-issues':
    $u=requireLogin();$sql="SELECT i.id,i.issue_date,i.due_date,i.returned_at,i.status,b.title,a.name author,bc.accession_no,CASE WHEN i.returned_at IS NULL AND CURDATE()>i.due_date THEN DATEDIFF(CURDATE(),i.due_date) ELSE 0 END overdue_days FROM issues i JOIN book_copies bc ON bc.id=i.copy_id JOIN books b ON b.id=bc.book_id JOIN authors a ON a.id=b.author_id WHERE i.student_id=? ORDER BY i.id DESC";$s=db()->prepare($sql);$s->execute([(int)$u['id']]);jsonResponse(['ok'=>true,'issues'=>$s->fetchAll()]);
  case 'all-issues': requireLogin('assistant');$s=db()->query("SELECT i.*,u.full_name student_name,u.student_id,b.title,bc.accession_no FROM issues i JOIN users u ON u.id=i.student_id JOIN book_copies bc ON bc.id=i.copy_id JOIN books b ON b.id=bc.book_id ORDER BY i.id DESC");jsonResponse(['ok'=>true,'issues'=>$s->fetchAll()]);
  case 'return':
    $u=requireLogin('assistant');requireCsrf();$d=requestJson();$iid=(int)($d['issue_id']??0);$pdo=db();$cfg=librarySettings($pdo);$pdo->beginTransaction();try{$s=$pdo->prepare("SELECT * FROM issues WHERE id=? AND returned_at IS NULL FOR UPDATE");$s->execute([$iid]);$i=$s->fetch();if(!$i)throw new RuntimeException('Active issue not found.');$now=date('Y-m-d H:i:s');$overdue=max(0,(int)floor((strtotime(date('Y-m-d'))-strtotime($i['due_date']))/86400));$fine=$overdue*(float)$cfg['fine_per_day'];$pdo->prepare("UPDATE issues SET returned_at=?,status='returned',fine_amount=? WHERE id=?")->execute([$now,$fine,$iid]);$pdo->prepare("UPDATE book_copies SET status='available' WHERE id=?")->execute([(int)$i['copy_id']]);$pdo->prepare('INSERT INTO returns_log(issue_id,received_by,returned_at,overdue_days,fine_paid,remarks,condition_note) VALUES(?,?,?,?,?,?,?)')->execute([$iid,(int)$u['id'],$now,$overdue,$fine,clean((string)($d['remarks']??''))?:null,clean((string)($d['condition_note']??''))?:null]);if($fine>0)$pdo->prepare('INSERT INTO fines(issue_id,student_id,amount,reason) VALUES(?,?,?,?)')->execute([$iid,(int)$i['student_id'],$fine,'Late return - '.$overdue.' overdue day(s)']);$pdo->commit();logAction((int)$u['id'],'RETURN','issue',$iid,"Fine ₹$fine");jsonResponse(['ok'=>true,'message'=>$fine>0?"Returned. Late fine: ₹$fine":"Returned successfully. No fine.",'fine'=>$fine]);}catch(Throwable $e){$pdo->rollBack();jsonResponse(['ok'=>false,'message'=>$e->getMessage()],422);}
  case 'fines':
    $u=requireLogin();$pdo=db();$cfg=librarySettings($pdo);$sql="SELECT f.id,f.amount,f.status,f.created_at,f.paid_at,i.issue_date,i.due_date,b.title
      FROM fines f JOIN issues i ON i.id=f.issue_id JOIN book_copies bc ON bc.id=i.copy_id JOIN books b ON b.id=bc.book_id
      WHERE f.student_id=? ORDER BY f.id DESC";$s=db()->prepare($sql);$s->execute([(int)$u['id']]);$rows=$s->fetchAll();
    $q=$pdo->prepare("SELECT i.id issue_id,i.issue_date,i.due_date,b.title,DATEDIFF(CURDATE(),i.due_date) overdue_days
      FROM issues i JOIN book_copies bc ON bc.id=i.copy_id JOIN books b ON b.id=bc.book_id
      WHERE i.student_id=? AND i.returned_at IS NULL AND CURDATE()>i.due_date AND NOT EXISTS(SELECT 1 FROM fines f WHERE f.issue_id=i.id)");
    $q->execute([(int)$u['id']]);foreach($q->fetchAll() as $x){$rows[]=['id'=>0,'amount'=>(float)$x['overdue_days']*(float)$cfg['fine_per_day'],'status'=>'pending_on_return','created_at'=>null,'paid_at'=>null,'issue_date'=>$x['issue_date'],'due_date'=>$x['due_date'],'title'=>$x['title']];}
    jsonResponse(['ok'=>true,'fines'=>$rows]);
  case 'all-fines':
    $u=requireLogin('assistant');$pdo=db();$cfg=librarySettings($pdo);$s=$pdo->query("SELECT f.*,u.full_name,u.student_id,b.title FROM fines f JOIN users u ON u.id=f.student_id JOIN issues i ON i.id=f.issue_id JOIN book_copies bc ON bc.id=i.copy_id JOIN books b ON b.id=bc.book_id ORDER BY f.id DESC");$rows=$s->fetchAll();
    $q=$pdo->query("SELECT i.id issue_id,i.student_id,u.full_name,u.student_id,b.title,i.issue_date,i.due_date,DATEDIFF(CURDATE(),i.due_date) overdue_days FROM issues i JOIN users u ON u.id=i.student_id JOIN book_copies bc ON bc.id=i.copy_id JOIN books b ON b.id=bc.book_id WHERE i.returned_at IS NULL AND CURDATE()>i.due_date AND NOT EXISTS(SELECT 1 FROM fines f WHERE f.issue_id=i.id)");
    foreach($q->fetchAll() as $x){$rows[]=['id'=>0,'issue_id'=>(int)$x['issue_id'],'student_id'=>(int)$x['student_id'],'full_name'=>$x['full_name'],'title'=>$x['title'],'amount'=>(float)$x['overdue_days']*(float)$cfg['fine_per_day'],'status'=>'pending_on_return','created_at'=>null,'paid_at'=>null,'due_date'=>$x['due_date']];}
    jsonResponse(['ok'=>true,'fines'=>$rows]);
  case 'handle-fine': $u=requireLogin('assistant');requireCsrf();$d=requestJson();$id=(int)($d['fine_id']??0);$status=in_array(($d['status']??''),['paid','waived'],true)?$d['status']:'';if(!$status)jsonResponse(['ok'=>false,'message'=>'Invalid fine status.'],422);$s=db()->prepare('UPDATE fines SET status=?,paid_at=?,handled_by=? WHERE id=? AND status="unpaid"');$s->execute([$status,$status==='paid'?date('Y-m-d H:i:s'):null,(int)$u['id'],$id]);if(!$s->rowCount())jsonResponse(['ok'=>false,'message'=>'Fine not found or already handled.'],409);jsonResponse(['ok'=>true,'message'=>'Fine updated.']);
  case 'reserve':
    $u=requireLogin('student');requireCsrf();$d=requestJson();$book=(int)($d['book_id']??0);
    if($book<=0)jsonResponse(['ok'=>false,'message'=>'Invalid book.'],422);
    $pdo=db();$s=$pdo->prepare('SELECT id,title FROM books WHERE id=?');$s->execute([$book]);$bk=$s->fetch();
    if(!$bk)jsonResponse(['ok'=>false,'message'=>'Book not found.'],404);
    $s=$pdo->prepare("SELECT id FROM reservations WHERE book_id=? AND student_id=? AND status IN ('waiting','approved')");$s->execute([$book,(int)$u['id']]);
    if($s->fetch())jsonResponse(['ok'=>false,'message'=>'You already have an active reservation for this book.'],409);
    $s=$pdo->prepare("INSERT INTO reservations(book_id,student_id,status,request_date,expiry_date) VALUES(?,?, 'waiting',CURDATE(),DATE_ADD(CURDATE(),INTERVAL 7 DAY))");$s->execute([$book,(int)$u['id']]);
    logAction((int)$u['id'],'RESERVE','book',$book);jsonResponse(['ok'=>true,'message'=>'Reservation request sent to the library.']);

  case 'reservations':
    $u=requireLogin();$where=$u['role']==='student'?'WHERE r.student_id=?':'';
    $sql="SELECT r.id,r.status,r.reserved_at,r.request_date,r.expiry_date,b.id book_id,b.title,u.full_name,u.student_id,
          (SELECT COUNT(*) FROM book_copies bc WHERE bc.book_id=b.id AND bc.status='available') available_copies
          FROM reservations r JOIN books b ON b.id=r.book_id JOIN users u ON u.id=r.student_id $where ORDER BY
          CASE r.status WHEN 'waiting' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END,r.id DESC";
    $s=db()->prepare($sql);$s->execute($u['role']==='student'?[(int)$u['id']]:[]);
    jsonResponse(['ok'=>true,'reservations'=>$s->fetchAll()]);

  case 'reservation-action':
    $d=requestJson();$rid=(int)($d['reservation_id']??0);$action=(string)($d['action']??'');
    if($rid<=0 || !in_array($action,['approve','reject','issue','cancel'],true)) jsonResponse(['ok'=>false,'message'=>'Invalid reservation action.'],422);
    if($action==='cancel'){$u=requireLogin('student');requireCsrf();$st=db()->prepare("UPDATE reservations SET status='cancelled' WHERE id=? AND student_id=? AND status IN ('waiting','approved')");$st->execute([$rid,(int)$u['id']]);if(!$st->rowCount())jsonResponse(['ok'=>false,'message'=>'Reservation cannot be cancelled.'],409);logAction((int)$u['id'],'CANCEL','reservation',$rid);jsonResponse(['ok'=>true,'message'=>'Reservation cancelled.']);}
    $u=requireLogin('assistant');requireCsrf();
    $pdo=db();$cfg=librarySettings($pdo);$pdo->beginTransaction();
    try{
      $s=$pdo->prepare("SELECT r.*,b.title FROM reservations r JOIN books b ON b.id=r.book_id WHERE r.id=? FOR UPDATE");$s->execute([$rid]);$r=$s->fetch();
      if(!$r)throw new RuntimeException('Reservation not found.');
      if($action==='approve'){
        if($r['status']!=='waiting')throw new RuntimeException('Only waiting reservations can be approved.');
        $pdo->prepare("UPDATE reservations SET status='approved' WHERE id=?")->execute([$rid]);
        $pdo->commit();logAction((int)$u['id'],'APPROVE','reservation',$rid,$r['title']);jsonResponse(['ok'=>true,'message'=>'Reservation approved. Issue it when a copy is available.']);
      }
      if($action==='reject'){
        if(!in_array($r['status'],['waiting','approved'],true))throw new RuntimeException('This reservation is already closed.');
        $pdo->prepare("UPDATE reservations SET status='rejected' WHERE id=?")->execute([$rid]);
        $pdo->commit();logAction((int)$u['id'],'REJECT','reservation',$rid,$r['title']);jsonResponse(['ok'=>true,'message'=>'Reservation rejected.']);
      }
      if($r['status']!=='approved')throw new RuntimeException('Approve the reservation first.');
      $s=$pdo->prepare("SELECT id FROM book_copies WHERE book_id=? AND status='available' ORDER BY id LIMIT 1 FOR UPDATE");$s->execute([(int)$r['book_id']]);$copy=$s->fetch();
      if(!$copy)throw new RuntimeException('No copy is available yet. Keep the reservation approved until a copy is returned.');
      $s=$pdo->prepare("SELECT i.id FROM issues i JOIN book_copies bc ON bc.id=i.copy_id WHERE i.student_id=? AND bc.book_id=? AND i.returned_at IS NULL LIMIT 1");$s->execute([(int)$r['student_id'],(int)$r['book_id']]);
      if($s->fetch())throw new RuntimeException('This student already has this title issued.');
      $issue=date('Y-m-d');$requestedDue=clean((string)($d['due_date']??''));$due=$requestedDue&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$requestedDue)?$requestedDue:date('Y-m-d',strtotime('+'.((int)$cfg['loan_days']).' days'));
      if(strtotime($due)<strtotime($issue))throw new RuntimeException('Due date cannot be before issue date.');
      $pdo->prepare('INSERT INTO issues(copy_id,student_id,issued_by,issue_date,due_date) VALUES(?,?,?,?,?)')->execute([(int)$copy['id'],(int)$r['student_id'],(int)$u['id'],$issue,$due]);
      $iid=(int)$pdo->lastInsertId();$pdo->prepare("UPDATE book_copies SET status='issued' WHERE id=?")->execute([(int)$copy['id']]);
      $pdo->prepare("UPDATE reservations SET status='fulfilled',fulfilled_at=? WHERE id=?")->execute([date('Y-m-d H:i:s'),$rid]);
      $pdo->commit();logAction((int)$u['id'],'ISSUE_RESERVED','issue',$iid,'Reservation '.$rid.' / '.$r['title']);
      jsonResponse(['ok'=>true,'message'=>"Reserved book issued successfully. Due date: $due",'due_date'=>$due]);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();jsonResponse(['ok'=>false,'message'=>$e->getMessage()],422);}

  case 'update-due-date':
    $u=requireLogin('assistant');requireCsrf();$d=requestJson();$iid=(int)($d['issue_id']??0);$due=clean((string)($d['due_date']??''));
    if($iid<=0||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$due))jsonResponse(['ok'=>false,'message'=>'Enter a valid due date.'],422);
    $pdo=db();$s=$pdo->prepare("SELECT issue_date,returned_at FROM issues WHERE id=?");$s->execute([$iid]);$i=$s->fetch();
    if(!$i)jsonResponse(['ok'=>false,'message'=>'Issue not found.'],404);
    if($i['returned_at'])jsonResponse(['ok'=>false,'message'=>'Returned books cannot have their due date changed.'],409);
    if(strtotime($due)<strtotime($i['issue_date']))jsonResponse(['ok'=>false,'message'=>'Due date cannot be before issue date.'],422);
    $pdo->prepare('UPDATE issues SET due_date=?,status=CASE WHEN returned_at IS NULL AND ? < CURDATE() THEN "overdue" ELSE "issued" END WHERE id=?')->execute([$due,$due,$iid]);
    logAction((int)$u['id'],'UPDATE_DUE_DATE','issue',$iid,'Due date '.$due);
    jsonResponse(['ok'=>true,'message'=>"Due date updated to $due.",'due_date'=>$due]);

  case 'dashboard':
    $u=requireLogin();$pdo=db();if($u['role']==='assistant'){$stats=[];$stats['books']=(int)$pdo->query('SELECT COUNT(*) FROM books')->fetchColumn();$stats['available']=(int)$pdo->query("SELECT COUNT(*) FROM book_copies WHERE status='available'")->fetchColumn();$stats['students']=(int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='student' AND status='active'")->fetchColumn();$stats['active_issues']=(int)$pdo->query("SELECT COUNT(*) FROM issues WHERE returned_at IS NULL")->fetchColumn();$stats['unpaid_fines']=(float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM fines WHERE status='unpaid'")->fetchColumn();jsonResponse(['ok'=>true,'stats'=>$stats]);}else{$s=$pdo->prepare("SELECT COUNT(*) FROM issues WHERE student_id=? AND returned_at IS NULL");$s->execute([(int)$u['id']]);$active=(int)$s->fetchColumn();$s=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM fines WHERE student_id=? AND status='unpaid'");$s->execute([(int)$u['id']]);$fine=(float)$s->fetchColumn();$available=(int)$pdo->query("SELECT COUNT(*) FROM book_copies WHERE status='available'")->fetchColumn();jsonResponse(['ok'=>true,'stats'=>['active_issues'=>$active,'unpaid_fines'=>$fine,'available'=>$available]]);}
  case 'audit': requireLogin('assistant');$s=db()->query("SELECT a.*,COALESCE(u.full_name,'System') user_name FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 100");jsonResponse(['ok'=>true,'logs'=>$s->fetchAll()]);

  case 'reports':
    requireLogin('assistant'); $pdo=db();
    $reports=[
      'books'=>(int)$pdo->query('SELECT COUNT(*) FROM books')->fetchColumn(),
      'copies'=>(int)$pdo->query('SELECT COUNT(*) FROM book_copies')->fetchColumn(),
      'members'=>(int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='student' AND status='active'")->fetchColumn(),
      'issued'=>(int)$pdo->query("SELECT COUNT(*) FROM issues WHERE returned_at IS NULL")->fetchColumn(),
      'overdue'=>(int)$pdo->query("SELECT COUNT(*) FROM issues WHERE returned_at IS NULL AND due_date<CURDATE()")->fetchColumn(),
      'reservations'=>(int)$pdo->query("SELECT COUNT(*) FROM reservations WHERE status IN ('waiting','approved')")->fetchColumn(),
      'unpaid_fines'=>(float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM fines WHERE status='unpaid'")->fetchColumn(),
      'top_books'=>$pdo->query("SELECT b.title,COUNT(*) borrow_count FROM issues i JOIN book_copies bc ON bc.id=i.copy_id JOIN books b ON b.id=bc.book_id GROUP BY b.id ORDER BY borrow_count DESC,b.title LIMIT 10")->fetchAll(),
      'overdue_list'=>$pdo->query("SELECT u.full_name,b.title,i.due_date,DATEDIFF(CURDATE(),i.due_date) overdue_days FROM issues i JOIN users u ON u.id=i.student_id JOIN book_copies bc ON bc.id=i.copy_id JOIN books b ON b.id=bc.book_id WHERE i.returned_at IS NULL AND i.due_date<CURDATE() ORDER BY overdue_days DESC LIMIT 20")->fetchAll()
    ]; jsonResponse(['ok'=>true,'reports'=>$reports]);
  case 'settings':
    $u=requireLogin();$pdo=db();
    if($method==='GET'){ jsonResponse(['ok'=>true,'settings'=>librarySettings($pdo)]); }
    requireCsrf(); if($u['role']!=='assistant') jsonResponse(['ok'=>false,'message'=>'Only librarians can change settings.'],403); $d=requestJson();
    $loan=max(1,min(365,(int)($d['loan_days']??14))); $fine=max(0,min(10000,(float)($d['fine_per_day']??5)));$name=clean((string)($d['library_name']??'Library Management System'));$ln=clean((string)($d['librarian_name']??''));$email=clean((string)($d['email']??''));$phone=clean((string)($d['phone']??''));$address=clean((string)($d['address']??''));
    $st=$pdo->prepare('UPDATE library_settings SET loan_days=?,fine_per_day=?,library_name=?,librarian_name=?,email=?,phone=?,address=? WHERE id=1');$st->execute([$loan,$fine,$name,$ln?:null,$email?:null,$phone?:null,$address?:null]);logAction((int)$u['id'],'UPDATE','settings',1,'Library settings updated');jsonResponse(['ok'=>true,'message'=>'Library settings updated.','settings'=>librarySettings($pdo)]);
  case 'member-create':
    $u=requireLogin('assistant');requireCsrf();$d=requestJson();$name=clean((string)($d['name']??''));$email=strtolower(clean((string)($d['email']??'')));$phone=clean((string)($d['phone']??''));$type=in_array(($d['member_type']??'Student'),['Student','Faculty','Other'],true)?$d['member_type']:'Student';$address=clean((string)($d['address']??''));$pass=(string)($d['password']??'Library@123');if(mb_strlen($name)<2||!validateEmail($email)||mb_strlen($pass)<8)jsonResponse(['ok'=>false,'message'=>'Enter valid member details and password (8+ chars).'],422);$pdo=db();$st=$pdo->prepare('SELECT id FROM users WHERE email=?');$st->execute([$email]);if($st->fetch())jsonResponse(['ok'=>false,'message'=>'Email already exists.'],409);$sid=uniqueStudentId($pdo);$st=$pdo->prepare("INSERT INTO users(full_name,email,phone,password_hash,role,student_id,member_type,address) VALUES(?,?,?,?, 'student',?,?,?)");$st->execute([$name,$email,$phone?:null,password_hash($pass,PASSWORD_DEFAULT),$sid,$type,$address?:null]);$id=(int)$pdo->lastInsertId();logAction((int)$u['id'],'CREATE','member',$id,$name);jsonResponse(['ok'=>true,'message'=>'Member created successfully.','member_id'=>$sid]);
  case 'member-action':
    $u=requireLogin('assistant');requireCsrf();$d=requestJson();$id=(int)($d['member_id']??0);$action=(string)($d['action']??'');if($id<=0||!in_array($action,['activate','deactivate'],true))jsonResponse(['ok'=>false,'message'=>'Invalid member action.'],422);$status=$action==='activate'?'active':'blocked';$st=db()->prepare("UPDATE users SET status=? WHERE id=? AND role='student'");$st->execute([$status,$id]);if(!$st->rowCount())jsonResponse(['ok'=>false,'message'=>'Member not found or unchanged.'],404);logAction((int)$u['id'],strtoupper($action),'member',$id);jsonResponse(['ok'=>true,'message'=>'Member status updated.']);
  case 'author-action':
    $u=requireLogin('assistant');requireCsrf();$d=requestJson();$id=(int)($d['id']??0);$action=(string)($d['action']??'');$name=clean((string)($d['name']??''));$bio=clean((string)($d['bio']??''));$pdo=db();
    if($action==='save'){if(!$name)jsonResponse(['ok'=>false,'message'=>'Author name is required.'],422);if($id){$st=$pdo->prepare('UPDATE authors SET name=?,bio=? WHERE id=?');$st->execute([$name,$bio?:null,$id]);}else{$st=$pdo->prepare('INSERT INTO authors(name,bio) VALUES(?,?)');$st->execute([$name,$bio?:null]);$id=(int)$pdo->lastInsertId();}jsonResponse(['ok'=>true,'message'=>'Author saved.']);}
    if($action==='delete'){try{$pdo->prepare('DELETE FROM authors WHERE id=?')->execute([$id]);jsonResponse(['ok'=>true,'message'=>'Author deleted.']);}catch(Throwable $e){jsonResponse(['ok'=>false,'message'=>'Author cannot be deleted while linked to books.'],409);}}jsonResponse(['ok'=>false,'message'=>'Invalid author action.'],422);
  case 'category-action':
    $u=requireLogin('assistant');requireCsrf();$d=requestJson();$id=(int)($d['id']??0);$action=(string)($d['action']??'');$name=clean((string)($d['name']??''));$desc=clean((string)($d['description']??''));$pdo=db();
    if($action==='save'){if(!$name)jsonResponse(['ok'=>false,'message'=>'Category name is required.'],422);if($id){$st=$pdo->prepare('UPDATE categories SET name=?,description=? WHERE id=?');$st->execute([$name,$desc?:null,$id]);}else{$st=$pdo->prepare('INSERT INTO categories(name,description) VALUES(?,?)');$st->execute([$name,$desc?:null]);$id=(int)$pdo->lastInsertId();}jsonResponse(['ok'=>true,'message'=>'Category saved.']);}
    if($action==='delete'){try{$pdo->prepare('DELETE FROM categories WHERE id=?')->execute([$id]);jsonResponse(['ok'=>true,'message'=>'Category deleted.']);}catch(Throwable $e){jsonResponse(['ok'=>false,'message'=>'Category cannot be deleted while linked to books.'],409);}}jsonResponse(['ok'=>false,'message'=>'Invalid category action.'],422);

  default: jsonResponse(['ok'=>false,'message'=>'API route not found.'],404);
 }
} catch (Throwable $e) {
    $msg = $e instanceof RuntimeException ? $e->getMessage() : 'Something went wrong on the server.';
    error_log('[Library Management System API] ' . $e->getMessage());
    jsonResponse(['ok'=>false,'message'=>$msg],500);
}
