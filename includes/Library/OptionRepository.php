<?php
namespace Eponymos\Library;

class OptionRepository {
    private function table($name) { global $wpdb; return $wpdb->prefix . 'eponymos_' . $name; }
    public function groups() { global $wpdb; $groups=$wpdb->get_results('SELECT * FROM '.$this->table('option_groups').' ORDER BY updated_at DESC,id DESC', ARRAY_A); if(!$groups)return []; $ids=array_column($groups,'id'); $placeholders=implode(',',array_fill(0,count($ids),'%d')); $options=$wpdb->get_results($wpdb->prepare("SELECT * FROM ".$this->table('library_options')." WHERE group_id IN ($placeholders) ORDER BY position,id",$ids),ARRAY_A); $by_group=[]; foreach($options as $o)$by_group[(int)$o['group_id']][]=$o; foreach($groups as &$g)$g['options']=$by_group[(int)$g['id']]??[]; return $groups; }
    public function group($id) { global $wpdb; $group=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$this->table('option_groups').' WHERE id=%d',absint($id)),ARRAY_A); if(!$group)return null; $group['options']=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.$this->table('library_options').' WHERE group_id=%d ORDER BY position,id',$group['id']),ARRAY_A); return $group; }
    public function create_group($name,$description='') { global $wpdb; $now=current_time('mysql'); $wpdb->insert($this->table('option_groups'),['name'=>$name,'description'=>$description,'hide_title'=>0,'status'=>'active','created_at'=>$now,'updated_at'=>$now],['%s','%s','%d','%s','%s','%s']); return (int)$wpdb->insert_id; }

    public function update_group($id,$data) { global $wpdb; $data['updated_at']=current_time('mysql'); return $wpdb->update($this->table('option_groups'),$data,['id'=>absint($id)],null,['%d']); }
    public function delete_group($id) { global $wpdb; $wpdb->delete($this->table('library_options'),['group_id'=>absint($id)],['%d']); return $wpdb->delete($this->table('option_groups'),['id'=>absint($id)],['%d']); }
    public function create_option($group_id,$label,$value='',$key='') { global $wpdb; $max=$wpdb->get_var($wpdb->prepare('SELECT COALESCE(MAX(position),-1)+1 FROM '.$this->table('library_options').' WHERE group_id=%d',$group_id)); if(!$key)$key=sanitize_key($label); $wpdb->insert($this->table('library_options'),['group_id'=>absint($group_id),'label'=>$label,'option_key'=>$key,'calculation_value'=>$value,'status'=>'active','position'=>(int)$max],['%d','%s','%s','%s','%s','%d']); return (int)$wpdb->insert_id; }
    public function update_option($id,$data) { global $wpdb; return $wpdb->update($this->table('library_options'),$data,['id'=>absint($id)],null,['%d']); }
    public function delete_option($id) { global $wpdb; return $wpdb->delete($this->table('library_options'),['id'=>absint($id)],['%d']); }
}
