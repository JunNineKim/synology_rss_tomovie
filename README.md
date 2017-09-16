# Synology Download Station TOMOVIE Custom-made RSS.
  - 시놀로지 다운로드 스테이션용 TOMOVIE 사이트 RSS.
  - RSS로 Download하는 파일의 중복 Download를 방지한다.
    Synology 에 Maria DB를 설치하여 Table에 저장관리.
  - RSS Feeds에 등록 예시.
  - http://localhost/rss/rss_tomovie.php?k=문화공간+720p-NEXT
    
# 설치순서
  1. RSS Schedule을 중지합니다.
  2. Synology Maria DB5 에 Data dase 생성.
     1) phpmyadmin을 사용하여 root 로그인합니다.
     2) 화면 우측의 구조 우측의 SQL을 누릅니다.
     3) DB생성(있다면 생략)
        create database torrent;
        use torrent;
  3. 테이블 생성 작업을 합니다(2개).
     create table rss_torrent
     (
         rss_id     int not null AUTO_INCREMENT primary key,
         w_h_flag   varchar(1)   default 'N',
         hash       varchar(40)  not null ,
         action     varchar(10)  not null,
         title      varchar(200) not null,
         chk_date   timestamp    default CURRENT_TIMESTAMP,
         reg_date   timestamp    default CURRENT_TIMESTAMP,
         rss_torrent_n1(reg_date),
         rss_torrent_n2(hash),
         rss_torrent_n3(title),
         rss_torrent_n4(action,reg_date)
     );
    CREATE TABLE wr_log
    ( log_id        int not null AUTO_INCREMENT ,
      log           varchar(2000) ,
      creation_date timestamp default CURRENT_TIMESTAMP ,
      CONSTRAINT wr_log_pk PRIMARY KEY (log_id),
      wr_log_n1(creation_date)
    );
  4. rss_tomovie.php의 database 비밀번호를 등록 저장합니다.
  5. rss 스케줄을 올립니다.
  6. rss 등록 및 상태를 관찰 합니다.

